-- ONE-TIME DESTRUCTIVE PRESENTATION DATA RESET; never run from a migration/CI.
-- Preserve existing Administration Managers and the application/platform schema.
-- All new people, quantities, supplier/planning data and transactions are synthetic.
-- Filter dates are synthetic warehouse expiry scenarios, not manufacturer shelf-life claims.
-- Purifier and thermostat hardware has no artificial expiry date.
-- Export and validate the current data outside Git before changing either guard.
-- Run as postgres in the reviewed Supabase project, during a quiet period.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
SET LOCAL wba.reset_confirmation = 'NOT_APPROVED';
SET LOCAL wba.backup_confirmed = 'false';

LOCK TABLE public.users,public.products,public.batches,public.inventory_movements,
  public.inventory_adjustments,public.audit_logs,public.notifications,
  public.personal_access_tokens,public.sessions,public.password_reset_tokens,
  public.cache,public.cache_locks IN ACCESS EXCLUSIVE MODE;

DO $preflight$
BEGIN
  IF current_setting('wba.reset_confirmation',true) <> 'RESET WBA DEMO DATA'
     OR current_setting('wba.backup_confirmed',true) <> 'true' THEN
    RAISE EXCEPTION 'STOP: explicit reset approval and a validated external backup are required.';
  END IF;
  IF EXISTS (SELECT 1 FROM auth.users) OR EXISTS (SELECT 1 FROM public.users WHERE auth_user_id IS NOT NULL) THEN
    RAISE EXCEPTION 'This reset is for staged Laravel authentication only; linked Auth accounts need a separate reviewed migration.';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM public.users WHERE email='aljrome@gmail.com' AND department='Administration' AND role_level='Manager' AND is_active AND deleted_at IS NULL) THEN
    RAISE EXCEPTION 'The existing authorizing Administration Manager was not found.';
  END IF;
  IF (SELECT count(*) FROM public.users)<>16 OR (SELECT count(*) FROM public.products)<>4
     OR (SELECT count(*) FROM public.batches)<>8 OR (SELECT count(*) FROM public.inventory_movements)<>14
     OR (SELECT count(*) FROM public.inventory_adjustments)<>5 OR (SELECT count(*) FROM public.audit_logs)<>69 THEN
    RAISE EXCEPTION 'The reviewed dataset changed. Take a fresh backup and review again before resetting.';
  END IF;
  IF EXISTS (SELECT 1 FROM public.jobs) THEN
    RAISE EXCEPTION 'Pending jobs must finish before resetting inventory.';
  END IF;
END $preflight$;

CREATE TEMP TABLE wba_preserved_admins ON COMMIT DROP AS
  SELECT * FROM public.users WHERE department='Administration' AND role_level='Manager';
CREATE TEMP TABLE wba_demo_credentials (
  id bigint, first_name text NOT NULL,last_name text NOT NULL,email text PRIMARY KEY,
  department text NOT NULL,role_level text NOT NULL,temporary_password text NOT NULL
) ON COMMIT PRESERVE ROWS;

DELETE FROM public.inventory_movements;
DELETE FROM public.inventory_adjustments;
UPDATE public.batches SET origin_batch_id=NULL;
DELETE FROM public.batches;
DELETE FROM public.products;
DELETE FROM public.audit_logs;
DELETE FROM public.notifications;
DELETE FROM public.personal_access_tokens;
DELETE FROM public.sessions;
DELETE FROM public.password_reset_tokens;
DELETE FROM public.cache;
DELETE FROM public.cache_locks;
DELETE FROM public.users WHERE NOT (department='Administration' AND role_level='Manager');

INSERT INTO wba_demo_credentials(first_name,last_name,email,department,role_level,temporary_password)
SELECT first_name,last_name,email,department,role_level,
  encode(extensions.gen_random_bytes(18),'base64')
FROM (VALUES
  ('Maya','Santos','demo.warehouse.staff@wba.example','Warehouse','Staff'),
  ('Nico','Reyes','demo.warehouse.supervisor@wba.example','Warehouse','Supervisor'),
  ('Andre','Cruz','demo.warehouse.manager@wba.example','Warehouse','Manager'),
  ('Bea','Ramos','demo.sales.staff@wba.example','Sales','Staff'),
  ('Paolo','Garcia','demo.sales.supervisor@wba.example','Sales','Supervisor'),
  ('Ella','Mendoza','demo.purchasing.staff@wba.example','Purchasing','Staff'),
  ('Luis','Navarro','demo.purchasing.manager@wba.example','Purchasing','Manager'),
  ('Sofia','Torres','demo.admin.manager@wba.example','Administration','Manager')
) AS roles(first_name,last_name,email,department,role_level);

INSERT INTO public.users(name,first_name,last_name,email,password,department,role_level,
  is_active,must_change_password,created_at,updated_at)
SELECT first_name||' '||last_name,first_name,last_name,email,
  replace(extensions.crypt(temporary_password,extensions.gen_salt('bf',12)),'$2a$','$2y$'),
  department,role_level,true,true,timezone('Asia/Manila',now()),timezone('Asia/Manila',now())
FROM wba_demo_credentials;
UPDATE wba_demo_credentials c SET id=u.id FROM public.users u WHERE u.email=c.email;

-- Session-only helper; no permanent SQL/RPC functions are added.
CREATE FUNCTION pg_temp.wba_demo_move(
  p_sku text,p_batch_number text,p_actor_email text,p_type text,p_quantity integer,
  p_reference text,p_stamp timestamp,p_destination text DEFAULT NULL
) RETURNS bigint LANGUAGE plpgsql AS $move$
DECLARE p public.products%ROWTYPE; b public.batches%ROWTYPE; actor public.users%ROWTYPE;
  destination_id bigint; movement_id bigint; before_stock integer; before_batch integer;
BEGIN
  SELECT * INTO STRICT p FROM public.products WHERE sku=p_sku FOR UPDATE;
  SELECT * INTO STRICT b FROM public.batches WHERE number=p_batch_number AND product_id=p.id FOR UPDATE;
  SELECT * INTO STRICT actor FROM public.users WHERE email=p_actor_email;
  IF p_quantity<=0 OR p_type NOT IN ('Stock In','Opening Balance','Stock Out','Transfer','Return') THEN
    RAISE EXCEPTION 'Invalid demo movement';
  END IF;
  IF b.expires_at IS NOT NULL AND b.expires_at<p_stamp::date THEN RAISE EXCEPTION 'Expired batch used at transaction time'; END IF;
  IF p_type IN ('Stock Out','Transfer') AND b.quantity<p_quantity THEN RAISE EXCEPTION 'Insufficient batch stock'; END IF;
  before_stock:=p.stock; before_batch:=b.quantity;
  IF p_type='Transfer' THEN
    INSERT INTO public.batches(product_id,origin_batch_id,number,quantity,received_at,expires_at,location,created_at,updated_at)
    VALUES(p.id,coalesce(b.origin_batch_id,b.id),'DEMO-AP-DISPATCH',p_quantity,b.received_at,b.expires_at,p_destination,p_stamp,p_stamp)
    RETURNING id INTO destination_id;
    UPDATE public.batches SET quantity=quantity-p_quantity,updated_at=p_stamp WHERE id=b.id;
  ELSE
    UPDATE public.batches SET quantity=quantity+CASE WHEN p_type IN ('Stock In','Opening Balance','Return') THEN p_quantity ELSE -p_quantity END,updated_at=p_stamp WHERE id=b.id;
    UPDATE public.products SET stock=stock+CASE WHEN p_type IN ('Stock In','Opening Balance','Return') THEN p_quantity ELSE -p_quantity END,updated_at=p_stamp WHERE id=p.id;
  END IF;
  UPDATE public.products SET stock_version=stock_version+1,updated_at=p_stamp WHERE id=p.id;
  INSERT INTO public.inventory_movements(product_id,batch_id,user_id,type,quantity,reference,status,
    stock_before,stock_after,batch_before,batch_after,destination_batch_id,source_location,destination_location,
    return_condition,created_at,updated_at)
  SELECT p.id,b.id,actor.id,p_type,p_quantity,'DEMO: '||p_reference,'Completed',before_stock,
    product.stock,before_batch,batch.quantity,destination_id,
    CASE WHEN p_type IN ('Stock Out','Transfer') THEN b.location END,
    CASE WHEN p_type='Transfer' THEN p_destination WHEN p_type IN ('Stock In','Opening Balance','Return') THEN b.location END,
    CASE WHEN p_type='Return' THEN 'Resaleable' END,p_stamp,p_stamp
  FROM public.products product CROSS JOIN public.batches batch WHERE product.id=p.id AND batch.id=b.id
  RETURNING id INTO movement_id;
  RETURN movement_id;
END $move$;

DO $catalogue$
DECLARE item record; lot record; product_id bigint; stamp timestamp:=timezone('Asia/Manila',now());
  day date:=(timezone('Asia/Manila',now()))::date; receipt_stamp timestamp; qty integer;
BEGIN
  FOR item IN SELECT * FROM (VALUES
    ('DEMO-SHP-FPJ30','Sharp Plasmacluster Air Purifier FP-J30E-A','Air Purifiers','Seasonal-Critical',12,60,14,24),
    ('DEMO-SHP-FPJ60','Sharp Plasmacluster Air Purifier FP-J60E-W','Air Purifiers','Stable-Demand',8,60,7,15),
    ('DEMO-HON-TH6210','Honeywell Home T6 Pro Programmable Thermostat TH6210U2001','Thermostats','Traceable-High Accuracy',6,40,10,12),
    ('DEMO-SHP-FZF30','Sharp Replacement HEPA Filter FZ-F30HFE','Replacement Filters','Perishable-Rotating',32,120,10,36),
    ('DEMO-SHP-FZJ80','Sharp Replacement HEPA Filter FZ-J80HFE','Replacement Filters','Perishable-Rotating',20,100,5,45),
    ('DEMO-SHP-FZF50','Sharp Replacement HEPA Filter FZ-F50HFE','Replacement Filters','Perishable-Rotating',10,80,7,20)
  ) AS catalogue(sku,name,category,pattern,reorder_point,capacity,lead_time,monthly_sales)
  LOOP
    INSERT INTO public.products(sku,name,category,demand_pattern,stock,reorder_point,capacity,supplier,lead_time,unit,
      monthly_sales,monthly_sales_history,seasonal_profile,stock_version,created_at,updated_at)
    SELECT item.sku,item.name,item.category,item.pattern,0,item.reorder_point,item.capacity,
      'Metro Manila Appliance Distribution (demo)',item.lead_time,'units',item.monthly_sales,
      (SELECT json_agg(json_build_object('month',to_char(month,'YYYY-MM'),'quantity',
        round(item.monthly_sales*CASE WHEN item.pattern='Seasonal-Critical' AND extract(month FROM month) IN (4,5,6) THEN 1.8 ELSE 1.0 END)::integer) ORDER BY month)
       FROM generate_series(date_trunc('month',stamp)-interval '12 months',date_trunc('month',stamp)-interval '1 month',interval '1 month') month),
      CASE WHEN item.pattern='Seasonal-Critical' THEN '[{"month":4,"multiplier":1.5},{"month":5,"multiplier":1.8},{"month":6,"multiplier":2.0}]'::json ELSE '[]'::json END,
      0,stamp-interval '100 days',stamp
    RETURNING id INTO product_id;
    FOR lot IN SELECT generate_series(1,CASE WHEN item.sku='DEMO-SHP-FPJ30' THEN 2 ELSE 3 END) AS position
    LOOP
      qty:=CASE item.sku WHEN 'DEMO-SHP-FPJ30' THEN (ARRAY[20,15])[lot.position]
        WHEN 'DEMO-SHP-FPJ60' THEN (ARRAY[10,8,6])[lot.position]
        WHEN 'DEMO-HON-TH6210' THEN (ARRAY[6,8,4])[lot.position]
        WHEN 'DEMO-SHP-FZF30' THEN (ARRAY[5,12,18])[lot.position]
        WHEN 'DEMO-SHP-FZJ80' THEN (ARRAY[45,48,0])[lot.position]
        ELSE (ARRAY[12,10,8])[lot.position] END;
      receipt_stamp:=(day-(ARRAY[90,45,5])[lot.position])+time '09:00';
      INSERT INTO public.batches(product_id,number,quantity,received_at,expires_at,location,created_at,updated_at)
      VALUES(product_id,item.sku||'-LOT-'||lot.position,0,receipt_stamp::date,
      CASE WHEN item.sku='DEMO-SHP-FZF30' THEN day+(ARRAY[-7,14,180])[lot.position] WHEN item.pattern='Perishable-Rotating' THEN day+(ARRAY[120,240,360])[lot.position] END,
        CASE WHEN item.pattern='Perishable-Rotating' THEN 'Filter shelf F-'||lot.position ELSE 'Storage rack '||lot.position END,
        receipt_stamp,receipt_stamp);
      IF qty>0 THEN
        PERFORM pg_temp.wba_demo_move(item.sku,item.sku||'-LOT-'||lot.position,'demo.warehouse.staff@wba.example',
          CASE WHEN item.sku='DEMO-SHP-FZF50' AND lot.position=1 THEN 'Opening Balance' ELSE 'Stock In' END,
          qty,'supplier receipt '||item.sku||' lot '||lot.position,receipt_stamp);
      END IF;
    END LOOP;
  END LOOP;
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ30','DEMO-SHP-FPJ30-LOT-1','demo.sales.staff@wba.example','Stock Out',18,'air purifier dispatch SO-1001',stamp-interval '4 days');
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ30','DEMO-SHP-FPJ30-LOT-2','demo.sales.supervisor@wba.example','Stock Out',9,'air purifier dispatch SO-1002',stamp-interval '3 days');
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ60','DEMO-SHP-FPJ60-LOT-1','demo.sales.staff@wba.example','Stock Out',4,'air purifier dispatch SO-1003',stamp-interval '3 days');
  PERFORM pg_temp.wba_demo_move('DEMO-HON-TH6210','DEMO-HON-TH6210-LOT-1','demo.sales.staff@wba.example','Stock Out',1,'thermostat dispatch SO-1004',stamp-interval '3 days');
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ30','DEMO-SHP-FPJ30-LOT-1','demo.warehouse.supervisor@wba.example','Transfer',1,'rack-to-dispatch internal transfer',stamp-interval '2 days','Dispatch bay A');
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ30','DEMO-SHP-FPJ30-LOT-2','demo.warehouse.staff@wba.example','Stock In',2,'supplementary air purifier receipt',stamp-interval '1 day');
  PERFORM pg_temp.wba_demo_move('DEMO-SHP-FPJ60','DEMO-SHP-FPJ60-LOT-1','demo.warehouse.staff@wba.example','Return',2,'unopened air purifier return RT-1001',stamp-interval '6 hours');
  PERFORM pg_temp.wba_demo_move('DEMO-HON-TH6210','DEMO-HON-TH6210-LOT-1','demo.warehouse.staff@wba.example','Return',1,'unopened thermostat return RT-1002',stamp-interval '5 hours');
END $catalogue$;

DO $counts$
DECLARE scenario record; p public.products%ROWTYPE; request_id bigint; requester bigint; reviewer bigint;
  counts jsonb; proposed integer; stamp timestamp:=timezone('Asia/Manila',now());
BEGIN
  SELECT id INTO STRICT requester FROM public.users WHERE email='demo.warehouse.staff@wba.example';
  FOR scenario IN SELECT * FROM (VALUES
    ('DEMO-SHP-FZF50','Approved',-2,'demo.warehouse.manager@wba.example','Cycle count — DEMO: verified shelf count; two units missing from lot 2'),
    ('DEMO-HON-TH6210','Rejected',1,'demo.purchasing.manager@wba.example','DEMO: duplicate-counted thermostat; supervisor rejected the proposed increase'),
    ('DEMO-SHP-FZF30','Pending',-1,NULL,'Cycle count — DEMO: filter recount awaiting an independent reviewer')
  ) AS scenarios(sku,status,difference,reviewer_email,reason)
  LOOP
    SELECT * INTO STRICT p FROM public.products WHERE sku=scenario.sku;
    SELECT jsonb_agg(jsonb_build_object('batchId',id,'quantity',quantity+
      CASE WHEN number=scenario.sku||'-LOT-2' THEN scenario.difference ELSE 0 END) ORDER BY id)
    INTO counts FROM public.batches WHERE product_id=p.id;
    proposed:=p.stock+scenario.difference; reviewer:=NULL;
    IF scenario.reviewer_email IS NOT NULL THEN SELECT id INTO STRICT reviewer FROM public.users WHERE email=scenario.reviewer_email; END IF;
    INSERT INTO public.inventory_adjustments(product_id,system_qty,requested_qty,reason,requested_by,reviewed_by,
      status,reviewed_at,stock_version,batch_counts,created_at,updated_at)
    VALUES(p.id,p.stock,proposed,scenario.reason,requester,reviewer,scenario.status,
      CASE WHEN reviewer IS NOT NULL THEN stamp-interval '1 hour' END,p.stock_version,counts::json,
      stamp-interval '2 hours',stamp-interval '1 hour') RETURNING id INTO request_id;
    IF scenario.status='Approved' THEN
      UPDATE public.batches b SET quantity=(c->>'quantity')::integer,updated_at=stamp-interval '1 hour'
      FROM jsonb_array_elements(counts) c WHERE b.id=(c->>'batchId')::bigint AND b.product_id=p.id;
      UPDATE public.products SET stock=proposed,stock_version=stock_version+1,updated_at=stamp-interval '1 hour' WHERE id=p.id;
      INSERT INTO public.inventory_movements(product_id,user_id,type,quantity,reference,status,stock_before,stock_after,created_at,updated_at)
      VALUES(p.id,reviewer,'Adjustment',abs(scenario.difference),'DEMO: approved adjustment '||request_id,'Completed',p.stock,proposed,stamp-interval '1 hour',stamp-interval '1 hour');
    END IF;
  END LOOP;
END $counts$;

INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT actor.id,actor.department,actor.role_level,'Presentation dataset reset','System',NULL,
  'Authorized synthetic demo reset. Previous data exported outside Git; existing Administration Managers preserved. All seeded people, suppliers, planning assumptions and stock are demonstration data, not verified physical inventory.',timezone('Asia/Manila',now()),timezone('Asia/Manila',now())
FROM public.users actor WHERE email='aljrome@gmail.com';
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT actor.id,actor.department,actor.role_level,'User created','Users',c.id::text,
  'DEMO account created: '||c.first_name||' '||c.last_name||'; temporary password must be changed before warehouse access.',timezone('Asia/Manila',now()),timezone('Asia/Manila',now())
FROM wba_demo_credentials c CROSS JOIN public.users actor WHERE actor.email='aljrome@gmail.com';
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT actor.id,actor.department,actor.role_level,'Product created','Products',p.id::text,'DEMO catalogue: '||p.name,p.created_at,p.created_at
FROM public.products p CROSS JOIN public.users actor WHERE actor.email='aljrome@gmail.com';
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT actor.id,actor.department,actor.role_level,'Batch created','Batches',b.id::text,'DEMO batch: '||b.number||' at '||b.location,b.created_at,b.created_at
FROM public.batches b CROSS JOIN public.users actor WHERE actor.email='aljrome@gmail.com';
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT u.id,u.department,u.role_level,m.type,'Inventory',m.id::text,m.reference,m.created_at,m.created_at
FROM public.inventory_movements m JOIN public.users u ON u.id=m.user_id;
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT u.id,u.department,u.role_level,'Adjustment requested','Adjustments',a.id::text,a.reason,a.created_at,a.created_at
FROM public.inventory_adjustments a JOIN public.users u ON u.id=a.requested_by;
INSERT INTO public.audit_logs(user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT u.id,u.department,u.role_level,'Adjustment '||lower(a.status),'Adjustments',a.id::text,a.reason,a.reviewed_at,a.reviewed_at
FROM public.inventory_adjustments a JOIN public.users u ON u.id=a.reviewed_by WHERE a.status<>'Pending';

INSERT INTO public.notifications(id,type,notifiable_type,notifiable_id,data,created_at,updated_at)
SELECT gen_random_uuid(),'App\Support\InventoryNotice','App\Models\User',u.id,
  json_build_object('title',m.type||': '||p.name,'message',m.reference||'; quantity '||m.quantity||' '||p.unit||'; recorded on hand '||m.stock_after||'. Demonstration data.',
    'severity','Info','productId',p.id,'movementId',m.id,'link','/inventory/'||p.id),m.created_at,m.created_at
FROM (SELECT DISTINCT ON (type) * FROM public.inventory_movements WHERE type<>'Opening Balance' ORDER BY type,created_at DESC,id DESC) m
JOIN public.products p ON p.id=m.product_id CROSS JOIN public.users u WHERE u.is_active AND u.deleted_at IS NULL;
INSERT INTO public.notifications(id,type,notifiable_type,notifiable_id,data,created_at,updated_at)
SELECT gen_random_uuid(),'App\Support\InventoryNotice','App\Models\User',u.id,
  json_build_object('title','Adjustment '||a.status||': '||p.name,'message',a.reason,
    'severity','High','productId',p.id,'adjustmentId',a.id,'requestedById',a.requested_by,'link','/adjustments'),a.updated_at,a.updated_at
FROM public.inventory_adjustments a JOIN public.products p ON p.id=a.product_id CROSS JOIN public.users u
WHERE u.is_active AND u.deleted_at IS NULL AND (u.id=a.requested_by OR
  (u.department='Warehouse' AND u.role_level IN ('Supervisor','Manager')) OR
  (u.department IN ('Purchasing','Administration') AND u.role_level='Manager'));

DO $verify$
BEGIN
  IF EXISTS ((SELECT * FROM wba_preserved_admins EXCEPT SELECT * FROM public.users)
    UNION ALL (SELECT * FROM public.users WHERE email IN (SELECT email FROM wba_preserved_admins) EXCEPT SELECT * FROM wba_preserved_admins)) THEN
    RAISE EXCEPTION 'Existing Administration Manager data changed; reset aborted.';
  END IF;
  IF (SELECT count(*) FROM public.users)<>14 OR (SELECT count(*) FROM wba_demo_credentials)<>8
     OR (SELECT count(*) FROM public.products)<>6 OR (SELECT count(*) FROM public.batches)<>18
     OR (SELECT count(*) FROM public.inventory_movements)<>25 OR (SELECT count(*) FROM public.inventory_adjustments)<>3 THEN
    RAISE EXCEPTION 'Unexpected demo dataset size';
  END IF;
  IF EXISTS (SELECT 1 FROM public.products p WHERE p.stock<>(SELECT coalesce(sum(quantity),0) FROM public.batches b WHERE b.product_id=p.id)) THEN
    RAISE EXCEPTION 'Product and batch totals are inconsistent';
  END IF;
  IF EXISTS (SELECT 1 FROM public.products p WHERE p.stock<>(SELECT coalesce(sum(stock_after-stock_before),0) FROM public.inventory_movements m WHERE m.product_id=p.id)) THEN
    RAISE EXCEPTION 'Product totals do not match the movement ledger';
  END IF;
  IF EXISTS (SELECT 1 FROM (SELECT stock_before,lag(stock_after,1,0) OVER(PARTITION BY product_id ORDER BY created_at,id) AS previous_stock FROM public.inventory_movements) ordered WHERE stock_before<>previous_stock) THEN
    RAISE EXCEPTION 'Movement stock history is discontinuous';
  END IF;
  IF (SELECT count(DISTINCT demand_pattern) FROM public.products)<>4 OR (SELECT count(DISTINCT type) FROM public.inventory_movements)<>6 THEN
    RAISE EXCEPTION 'Missing required demonstration tag or movement';
  END IF;
  IF EXISTS (SELECT 1 FROM (VALUES ('DEMO-SHP-FPJ30',10),('DEMO-SHP-FPJ60',22),('DEMO-HON-TH6210',18),
    ('DEMO-SHP-FZF30',35),('DEMO-SHP-FZJ80',93),('DEMO-SHP-FZF50',28)) expected(sku,quantity)
    JOIN public.products p USING(sku) WHERE p.stock<>expected.quantity) THEN
    RAISE EXCEPTION 'Unexpected demonstration stock totals';
  END IF;
  IF EXISTS (SELECT 1 FROM public.inventory_movements WHERE type='Transfer' AND stock_before<>stock_after)
     OR EXISTS (SELECT 1 FROM public.inventory_adjustments WHERE requested_by=reviewed_by) THEN
    RAISE EXCEPTION 'Transfer or independent-review invariants failed';
  END IF;
  IF EXISTS (SELECT 1 FROM public.users u JOIN wba_demo_credentials c ON c.id=u.id WHERE NOT u.must_change_password OR NOT u.is_active
    OR replace(extensions.crypt(c.temporary_password,replace(u.password,'$2y$','$2a$')),'$2a$','$2y$')<>u.password) THEN
    RAISE EXCEPTION 'Temporary credentials or mandatory password change failed';
  END IF;
END $verify$;
COMMIT;

-- Export this single result to a private CSV outside Git, then close the result.
-- Existing admin passwords are never returned or changed. Do not publish this CSV.
SELECT email,department,role_level AS role,temporary_password,
  CASE WHEN department='Administration' THEN '/#/admin/sign-in' ELSE '/#/sign-in' END AS login_portal,
  'Change this temporary password at first sign-in' AS instruction
FROM wba_demo_credentials ORDER BY department,role_level;
