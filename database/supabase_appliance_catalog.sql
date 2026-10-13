-- Optional appliance catalog. No invented stock, sales, receipt, expiry or supplier facts.
-- Existing app has SKU-level products and batches, not serial-number/maintenance assets.
-- Fill in an existing administrator email. New rows start with zero stock;
-- receive verified physical quantities through Stock In in the application.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL wba.seed_actor_email = 'aljrome@gmail.com';

DO $guard$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.users
        WHERE email = lower(btrim(current_setting('wba.seed_actor_email',true)))
          AND department = 'Administration' AND role_level = 'Manager'
          AND is_active AND deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Set wba.seed_actor_email to the active administrator authorizing the catalog import.';
    END IF;
END
$guard$;

WITH catalog(sku,name,category,demand_pattern) AS (
    VALUES
    ('WBA-CAR-WCART006EEV','Carrier Optima Inverter Window Air Conditioner WCART006EEV','Air Conditioners','Seasonal-Critical'),
    ('WBA-SAM-RT20FARVDSA','Samsung Top Mount Refrigerator RT20FARVDSA/TC','Refrigerators','Stable-Demand'),
    ('WBA-LG-TX2312DT5G','LG 12 kg Top Load Washing Machine TX2312DT5G','Washing Machines','Stable-Demand'),
    ('WBA-SHP-FPJ30EA','Sharp Air Purifier FP-J30E-A','Air Quality','Stable-Demand'),
    ('WBA-SHP-FZF30HFE','Sharp Replacement HEPA Filter FZ-F30HFE','Replacement Filters','Perishable-Rotating'),
    ('WBA-PAN-SRDF181','Panasonic Rice Cooker SR-DF181','Rice Cookers','Stable-Demand'),
    ('WBA-HAN-HBBSHLD16DF','Hanabishi Baby Shield Desk Fan HBBSHLD16DF','Electric Fans','Seasonal-Critical'),
    ('WBA-CON-MULTIDOOR','Condura No Frost Inverter Multi Door Refrigerator','Refrigerators','Stable-Demand')
), inserted AS (
    INSERT INTO public.products (sku,name,category,stock,reorder_point,capacity,supplier,lead_time,unit,monthly_sales,stock_version,demand_pattern,monthly_sales_history,seasonal_profile,created_at,updated_at)
    SELECT sku,name,category,0,0,NULL,NULL,0,'units',0,0,demand_pattern,NULL,NULL,
           timezone('Asia/Manila',CURRENT_TIMESTAMP),timezone('Asia/Manila',CURRENT_TIMESTAMP)
    FROM catalog
    ON CONFLICT (sku) DO NOTHING
    RETURNING id,sku,name
)
INSERT INTO public.audit_logs (user_id,department,role_level,action,module,reference,description,created_at,updated_at)
SELECT actor.id,actor.department,actor.role_level,'Product catalog imported','Inventory',inserted.id::text,
       inserted.sku || ': ' || inserted.name || '. Zero-stock catalog entry; verify supplier, lead time and receipt details in the application.',
       timezone('Asia/Manila',CURRENT_TIMESTAMP),timezone('Asia/Manila',CURRENT_TIMESTAMP)
FROM inserted CROSS JOIN public.users actor
WHERE actor.email = lower(btrim(current_setting('wba.seed_actor_email',true)))
  AND actor.department='Administration' AND actor.role_level='Manager'
  AND actor.is_active AND actor.deleted_at IS NULL;
COMMIT;
