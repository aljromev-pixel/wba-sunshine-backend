-- WBA: Laravel owns authentication; the browser must use the Laravel API.
-- Run as postgres in Supabase SQL Editor. No application rows are changed.
-- RLS intentionally has no browser-user policies. Do not FORCE RLS on the
-- table-owner connection used by Laravel. No auth.users/profile bridge is needed.
BEGIN;
SET LOCAL lock_timeout = '5s';

DO $security$
DECLARE
    table_name text;
    sequence_record record;
    application_tables constant text[] := ARRAY[
        'users', 'personal_access_tokens', 'sessions', 'password_reset_tokens',
        'products', 'batches', 'inventory_movements', 'inventory_adjustments',
        'audit_logs', 'notifications', 'cache', 'cache_locks', 'jobs',
        'job_batches', 'failed_jobs', 'migrations'
    ];
BEGIN
    FOREACH table_name IN ARRAY application_tables LOOP
        IF to_regclass(format('public.%I', table_name)) IS NULL THEN
            RAISE EXCEPTION 'Missing application table: %. Run the Laravel migrations first.', table_name;
        END IF;
        IF EXISTS (
            SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = table_name
              AND pg_get_userbyid(c.relowner) <> 'postgres'
        ) THEN
            RAISE EXCEPTION 'Unexpected owner for public.%. Verify the Laravel connection role first.', table_name;
        END IF;
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('REVOKE ALL PRIVILEGES ON TABLE public.%I FROM PUBLIC, anon, authenticated, service_role', table_name);
    END LOOP;

    FOR sequence_record IN
        SELECT DISTINCT ns.nspname, seq.relname
        FROM pg_class seq JOIN pg_namespace ns ON ns.oid = seq.relnamespace
        JOIN pg_depend dep ON dep.objid = seq.oid AND dep.deptype IN ('a', 'i')
        JOIN pg_class t ON t.oid = dep.refobjid
        JOIN pg_namespace tn ON tn.oid = t.relnamespace
        WHERE seq.relkind = 'S' AND tn.nspname = 'public'
          AND t.relname = ANY(application_tables)
    LOOP
        EXECUTE format('REVOKE ALL PRIVILEGES ON SEQUENCE %I.%I FROM PUBLIC, anon, authenticated, service_role',
            sequence_record.nspname, sequence_record.relname);
    END LOOP;
END
$security$;

-- Laravel creates schema objects as postgres. Stop automatic Data API grants
-- on future Laravel tables, sequences and RPC functions.
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    REVOKE ALL PRIVILEGES ON TABLES FROM PUBLIC, anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    REVOKE ALL PRIVILEGES ON SEQUENCES FROM PUBLIC, anon, authenticated, service_role;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC, anon, authenticated, service_role;

COMMIT;

SELECT c.relname AS table_name, c.relrowsecurity AS rls_enabled,
       has_table_privilege('anon', c.oid, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS anon_access,
       has_table_privilege('authenticated', c.oid, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS authenticated_access,
       has_table_privilege('service_role', c.oid, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS service_role_access
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
ORDER BY c.relname;
