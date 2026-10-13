-- Authorized one-time reconciliation of audited legacy stock, preserving product totals.
-- Set this to the existing administrator authorizing the repair, never a numeric user ID.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
SET LOCAL wba.repair_actor_email = 'aljrome@gmail.com';

DO $opening$
DECLARE
    actor public.users%ROWTYPE;
    item record;
    allocated bigint;
    missing integer;
    batch_id bigint;
    movement_id bigint;
    recorded_at timestamp := timezone('Asia/Manila', CURRENT_TIMESTAMP);
BEGIN
    SELECT * INTO actor FROM public.users
    WHERE email = lower(btrim(current_setting('wba.repair_actor_email', true)))
      AND department = 'Administration' AND role_level = 'Manager'
      AND is_active AND deleted_at IS NULL
    FOR UPDATE;
    IF actor.id IS NULL THEN
        RAISE EXCEPTION 'Set wba.repair_actor_email to the existing active administrator who authorized this repair.';
    END IF;

    FOR item IN
        SELECT p.*, expected.saved_stock, expected.unallocated
        FROM public.products p
        JOIN (VALUES ('AC-001',50,50), ('TH-001',12,12), ('APF-001',65,40)) AS expected(sku,saved_stock,unallocated)
          ON expected.sku = p.sku
        WHERE p.deleted_at IS NULL ORDER BY p.id FOR UPDATE OF p
    LOOP
        PERFORM id FROM public.batches WHERE product_id = item.id FOR UPDATE;
        SELECT COALESCE(sum(quantity),0) INTO allocated FROM public.batches WHERE product_id = item.id AND deleted_at IS NULL;
        missing := item.stock - allocated;
        IF missing = 0 THEN CONTINUE; END IF;
        IF item.stock <> item.saved_stock OR missing <> item.unallocated THEN
            RAISE EXCEPTION 'Stock changed for %. Re-audit before repair. Total %, allocated %.', item.sku,item.stock,allocated;
        END IF;

        INSERT INTO public.batches (product_id,number,quantity,received_at,expires_at,location,created_at,updated_at)
        VALUES (item.id,'LEGACY-OPEN-' || item.sku || '-20261014',missing,recorded_at::date,NULL,'Unverified legacy location',recorded_at,recorded_at)
        RETURNING id INTO batch_id;
        INSERT INTO public.inventory_movements (product_id,batch_id,user_id,type,quantity,reference,status,stock_before,stock_after,batch_before,batch_after,destination_location,created_at,updated_at)
        VALUES (item.id,batch_id,actor.id,'Opening Balance',missing,'Legacy allocation only; receipt date and location unverified; saved product total unchanged','Completed',allocated,item.stock,0,missing,'Unverified legacy location',recorded_at,recorded_at)
        RETURNING id INTO movement_id;
        UPDATE public.products SET stock_version = stock_version + 1, updated_at = recorded_at WHERE id = item.id;
        INSERT INTO public.audit_logs (user_id,department,role_level,action,module,reference,description,created_at,updated_at)
        VALUES (actor.id,actor.department,actor.role_level,'Legacy opening balance allocated','Inventory',movement_id::text,
            format('%s: %s existing units allocated. Product total remains %s. Original receipt date, physical location and expiry are unknown. received_at records this allocation date; verify physical stock before normal dispatch.',item.sku,missing,item.stock),recorded_at,recorded_at);
    END LOOP;
END
$opening$;
COMMIT;
