-- Existing Laravel schema repair, PostgreSQL 17 / Supabase.
-- Run after the existing Laravel migrations. No rows, tables, or application columns are removed.
-- Deploy the matching email-normalization API change before applying the email-format CHECK.
-- Canonical-equivalent email formatting is normalized and audited; collisions abort.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

DO $repair$
DECLARE item record;
BEGIN
    IF EXISTS (SELECT 1 FROM public.users GROUP BY lower(btrim(email)) HAVING count(*) > 1) THEN
        RAISE EXCEPTION 'Canonical email duplicates require manual account reconciliation; no accounts were changed.';
    END IF;
    WITH normalized AS (
        UPDATE public.users
        SET email = lower(btrim(email)), updated_at = timezone('Asia/Manila',CURRENT_TIMESTAMP)
        WHERE email <> lower(btrim(email))
        RETURNING id
    )
    INSERT INTO public.audit_logs (action,module,reference,description,created_at,updated_at)
    SELECT 'Email normalized','Users',id::text,
           'Database maintenance normalized email case and surrounding whitespace; account identity and credentials were preserved.',
           timezone('Asia/Manila',CURRENT_TIMESTAMP),timezone('Asia/Manila',CURRENT_TIMESTAMP)
    FROM normalized;

    FOR item IN SELECT * FROM (VALUES
        ('users', 'wba_users_email_canonical', 'CHECK (email = lower(btrim(email)) AND length(email) > 0)'),
        ('users', 'wba_users_job_role', 'CHECK ((department = ''Warehouse'' AND role_level IN (''Staff'',''Supervisor'',''Manager'')) OR (department = ''Sales'' AND role_level IN (''Staff'',''Supervisor'')) OR (department = ''Purchasing'' AND role_level IN (''Staff'',''Manager'')) OR (department = ''Administration'' AND role_level = ''Manager''))'),
        ('products', 'wba_products_quantities', 'CHECK (stock >= 0 AND reorder_point >= 0 AND (capacity IS NULL OR capacity >= 0) AND lead_time BETWEEN 0 AND 365 AND monthly_sales >= 0 AND stock_version >= 0)'),
        ('products', 'wba_products_identity', 'CHECK (length(btrim(sku)) > 0 AND length(btrim(name)) > 0 AND length(btrim(category)) > 0 AND length(btrim(unit)) > 0)'),
        ('products', 'wba_products_demand_pattern', 'CHECK (demand_pattern IN (''Seasonal-Critical'',''Perishable-Rotating'',''Stable-Demand'',''Traceable-High Accuracy''))'),
        ('products', 'wba_products_planning_arrays', 'CHECK ((monthly_sales_history IS NULL OR json_typeof(monthly_sales_history) = ''array'') AND (seasonal_profile IS NULL OR json_typeof(seasonal_profile) = ''array''))'),
        ('batches', 'wba_batches_integrity', 'CHECK (quantity >= 0 AND length(btrim(number)) > 0 AND length(btrim(location)) > 0 AND (expires_at IS NULL OR expires_at >= received_at) AND (origin_batch_id IS NULL OR origin_batch_id <> id))'),
        ('batches', 'wba_batches_product_identity', 'UNIQUE (id, product_id)'),
        ('inventory_movements', 'wba_movements_integrity', 'CHECK (quantity >= 0 AND type IN (''Stock In'',''Stock Out'',''Transfer'',''Return'',''Opening Balance'',''Adjustment'') AND status = ''Completed'' AND (stock_before IS NULL OR stock_before >= 0) AND (stock_after IS NULL OR stock_after >= 0) AND (batch_before IS NULL OR batch_before >= 0) AND (batch_after IS NULL OR batch_after >= 0))'),
        ('inventory_adjustments', 'wba_adjustments_integrity', 'CHECK (system_qty >= 0 AND requested_qty >= 0 AND (stock_version IS NULL OR stock_version >= 0) AND status IN (''Pending'',''Approved'',''Rejected'') AND (reviewed_by IS NULL OR reviewed_by <> requested_by) AND (batch_counts IS NULL OR json_typeof(batch_counts) = ''array''))'),
        ('personal_access_tokens', 'wba_tokens_user_model', 'CHECK (tokenable_type = ''App\Models\User'')'),
        ('notifications', 'wba_notifications_user_model', 'CHECK (notifiable_type = ''App\Models\User'')')
    ) AS changes(table_name, constraint_name, definition)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = format('public.%I', item.table_name)::regclass AND conname = item.constraint_name) THEN
            EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I %s', item.table_name, item.constraint_name, item.definition);
        END IF;
    END LOOP;

    -- Replace cascading physical deletion with history-preserving relationships.
    FOR item IN SELECT * FROM (VALUES
        ('batches', 'batches_product_id_foreign', 'FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT'),
        ('inventory_movements', 'inventory_movements_product_id_foreign', 'FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT'),
        ('inventory_adjustments', 'inventory_adjustments_product_id_foreign', 'FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT'),
        ('batches', 'batches_origin_batch_id_foreign', 'FOREIGN KEY (origin_batch_id, product_id) REFERENCES public.batches(id, product_id) ON DELETE RESTRICT'),
        ('inventory_movements', 'inventory_movements_batch_id_foreign', 'FOREIGN KEY (batch_id, product_id) REFERENCES public.batches(id, product_id) ON DELETE RESTRICT'),
        ('inventory_movements', 'inventory_movements_destination_batch_id_foreign', 'FOREIGN KEY (destination_batch_id, product_id) REFERENCES public.batches(id, product_id) ON DELETE RESTRICT')
    ) AS changes(table_name, constraint_name, definition)
    LOOP
        EXECUTE format('ALTER TABLE public.%I DROP CONSTRAINT IF EXISTS %I', item.table_name, item.constraint_name);
        EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I %s', item.table_name, item.constraint_name, item.definition);
    END LOOP;

    FOR item IN SELECT * FROM (VALUES
        ('sessions', 'wba_sessions_user_foreign', 'FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL'),
        ('personal_access_tokens', 'wba_tokens_user_foreign', 'FOREIGN KEY (tokenable_id) REFERENCES public.users(id) ON DELETE CASCADE'),
        ('notifications', 'wba_notifications_user_foreign', 'FOREIGN KEY (notifiable_id) REFERENCES public.users(id) ON DELETE CASCADE')
    ) AS changes(table_name, constraint_name, definition)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = format('public.%I', item.table_name)::regclass AND conname = item.constraint_name) THEN
            EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I %s', item.table_name, item.constraint_name, item.definition);
        END IF;
    END LOOP;
END
$repair$;

-- The existing exact-email unique constraint remains required by Laravel's validation.
CREATE UNIQUE INDEX IF NOT EXISTS wba_users_email_canonical_unique ON public.users (lower(btrim(email)));
CREATE INDEX IF NOT EXISTS wba_batches_active_fifo ON public.batches (product_id, received_at, id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS wba_movements_product_cursor ON public.inventory_movements (product_id, id DESC);
CREATE INDEX IF NOT EXISTS wba_adjustments_requester_cursor ON public.inventory_adjustments (requested_by, id DESC);
CREATE INDEX IF NOT EXISTS wba_adjustments_pending_cursor ON public.inventory_adjustments (id DESC) WHERE status = 'Pending';
COMMIT;
