-- WBA Admin/Staff separation for the EXISTING Laravel warehouse database.
-- Supabase owns auth.users and passwords. Preserve public.users.id and inventory/audit FKs.
-- Password-preserving imports use app:import-supabase-users and the official Auth Admin API.
-- No managed Auth tables are created/modified and no user UUID is hardcoded.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
DO $preflight$
BEGIN
    IF to_regclass('auth.users') IS NULL OR to_regclass('public.users') IS NULL THEN
        RAISE EXCEPTION 'Run in the existing Supabase project after the Laravel schema migrations.';
    END IF;
    IF EXISTS (SELECT 1 FROM public.users GROUP BY lower(btrim(email)) HAVING count(*)>1) THEN
        RAISE EXCEPTION 'Duplicate canonical emails require ownership reconciliation; no accounts were merged.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_policies WHERE schemaname='public' AND tablename='users' AND policyname<>'wba_profile_read') THEN
        RAISE EXCEPTION 'Unexpected users policies require review before installing the restricted profile policy.';
    END IF;
    IF EXISTS (SELECT 1 FROM public.users WHERE NOT (
        (department='Warehouse' AND role_level IN ('Staff','Supervisor','Manager')) OR
        (department='Sales' AND role_level IN ('Staff','Supervisor')) OR
        (department='Purchasing' AND role_level IN ('Staff','Manager')) OR
        (department='Administration' AND role_level='Manager')
    )) THEN RAISE EXCEPTION 'Invalid job roles must be reviewed before authentication migration.'; END IF;
END $preflight$;

ALTER TABLE public.users ADD COLUMN IF NOT EXISTS auth_user_id uuid;
ALTER TABLE public.users ALTER COLUMN password DROP NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS users_auth_user_id_unique ON public.users(auth_user_id);
DO $constraints$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='public.users'::regclass AND conname='wba_users_auth_identity') THEN
        ALTER TABLE public.users ADD CONSTRAINT wba_users_auth_identity
        FOREIGN KEY(auth_user_id) REFERENCES auth.users(id) ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='public.users'::regclass AND conname='wba_users_job_role') THEN
        ALTER TABLE public.users ADD CONSTRAINT wba_users_job_role CHECK (
            (department='Warehouse' AND role_level IN ('Staff','Supervisor','Manager')) OR
            (department='Sales' AND role_level IN ('Staff','Supervisor')) OR
            (department='Purchasing' AND role_level IN ('Staff','Manager')) OR
            (department='Administration' AND role_level='Manager')
        );
    END IF;
END $constraints$;

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


-- Resume only server-created imports carrying trusted app_metadata provenance.
-- Do NOT link an arbitrary matching email or trust editable user_metadata.
UPDATE public.users p SET auth_user_id=a.id
FROM auth.users a
WHERE p.auth_user_id IS NULL
  AND a.raw_app_meta_data->>'wba_legacy_user_id'=p.id::text
  AND lower(btrim(a.email))=lower(btrim(p.email));
CREATE INDEX IF NOT EXISTS wba_users_portal_membership
ON public.users(department,role_level,auth_user_id) WHERE is_active AND deleted_at IS NULL;
-- Old sessions never recorded a login portal; require a fresh, correctly scoped login.
DELETE FROM public.personal_access_tokens
WHERE NOT (coalesce(abilities,'[]')::jsonb ?| ARRAY['portal:admin','portal:staff']);

-- The helper returns one permission bit, never credentials or another account's UUID.
CREATE OR REPLACE FUNCTION public.wba_is_admin()
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=''
AS $function$
    SELECT EXISTS (
        SELECT 1 FROM public.users p WHERE p.auth_user_id=(SELECT auth.uid())
          AND p.department='Administration' AND p.role_level='Manager'
          AND p.is_active AND p.deleted_at IS NULL
    );
$function$;
REVOKE ALL ON FUNCTION public.wba_is_admin() FROM PUBLIC,anon,service_role;
GRANT EXECUTE ON FUNCTION public.wba_is_admin() TO authenticated;

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.users FROM PUBLIC,anon,authenticated,service_role;
GRANT SELECT(id,auth_user_id,name,first_name,last_name,email,department,role_level,is_active,must_change_password,created_at,updated_at)
ON public.users TO authenticated;
DROP POLICY IF EXISTS wba_profile_read ON public.users;
CREATE POLICY wba_profile_read ON public.users FOR SELECT TO authenticated
USING (
    (SELECT auth.uid()) IS NOT NULL AND is_active AND deleted_at IS NULL
    AND (
        auth_user_id=(SELECT auth.uid())
        OR ((SELECT public.wba_is_admin()) AND NOT (department='Administration' AND role_level='Manager'))
    )
);
-- No public insert/update/delete policy: role assignment remains server-admin-only.
-- Existing Laravel direct-owner writes still enforce job permissions and audit changes.

-- One canonical profile; two mutually exclusive views, not duplicate account tables.
CREATE OR REPLACE VIEW public.admin_accounts WITH(security_invoker=true,security_barrier=true) AS
SELECT auth_user_id AS id,id AS legacy_user_id,name,first_name,last_name,email,
       department,role_level,is_active,must_change_password,created_at,updated_at
FROM public.users WHERE auth_user_id IS NOT NULL AND department='Administration'
AND role_level='Manager' AND is_active;
CREATE OR REPLACE VIEW public.staff_accounts WITH(security_invoker=true,security_barrier=true) AS
SELECT auth_user_id AS id,id AS legacy_user_id,name,first_name,last_name,email,
       department,role_level,is_active,must_change_password,created_at,updated_at
FROM public.users WHERE auth_user_id IS NOT NULL
AND NOT (department='Administration' AND role_level='Manager') AND is_active;
REVOKE ALL ON public.admin_accounts,public.staff_accounts FROM PUBLIC,anon,authenticated,service_role;
GRANT SELECT ON public.admin_accounts,public.staff_accounts TO authenticated;

-- Finalization is deliberately separate from preparing the schema/importing identities.
-- Only the trusted database owner can call this after the hosted login smoke test.
CREATE OR REPLACE FUNCTION public.wba_require_auth_identity()
RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path=''
AS $function$
BEGIN
    IF EXISTS (SELECT 1 FROM public.users WHERE id=NEW.id AND auth_user_id IS NULL) THEN
        RAISE EXCEPTION 'A finalized warehouse profile requires a Supabase Auth identity' USING ERRCODE='23502';
    END IF;
    RETURN NULL;
END;
$function$;
REVOKE ALL ON FUNCTION public.wba_require_auth_identity() FROM PUBLIC,anon,authenticated,service_role;

CREATE OR REPLACE FUNCTION public.wba_finalize_auth_migration()
RETURNS void LANGUAGE plpgsql SECURITY INVOKER SET search_path=''
AS $function$
BEGIN
    IF current_setting('wba.confirm_auth_cutover',true) IS DISTINCT FROM 'yes' THEN
        RAISE EXCEPTION 'Verify backup/restore and hosted Supabase login before finalization';
    END IF;
    LOCK TABLE public.users IN SHARE ROW EXCLUSIVE MODE;
    IF EXISTS (SELECT 1 FROM public.users p LEFT JOIN auth.users a ON a.id=p.auth_user_id
        WHERE a.id IS NULL OR lower(btrim(a.email)) IS DISTINCT FROM lower(btrim(p.email))
        OR a.raw_app_meta_data->>'wba_legacy_user_id' IS DISTINCT FROM p.id::text) THEN
        RAISE EXCEPTION 'All profiles need a matching trusted Auth identity before finalization';
    END IF;
    UPDATE public.users SET password=NULL;
    DELETE FROM public.personal_access_tokens;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='public.users'::regclass AND tgname='wba_users_require_auth_identity') THEN
        CREATE CONSTRAINT TRIGGER wba_users_require_auth_identity AFTER INSERT OR UPDATE ON public.users
        DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.wba_require_auth_identity();
    END IF;
    INSERT INTO public.audit_logs(action,module,description,created_at,updated_at)
    VALUES ('Supabase authentication finalized','Users',
        'Verified identity import finalized; local password copies and pre-cutover sessions removed.',now(),now());
END;
$function$;
REVOKE ALL ON FUNCTION public.wba_finalize_auth_migration() FROM PUBLIC,anon,authenticated,service_role;

-- Inventory, audit, tokens and notifications remain accessible only through Laravel.
-- Do not copy the old Laravel-only security script over these profile column grants.
-- No auto-signup trigger: an unassigned Supabase signup obtains NO warehouse profile.
-- The application verifies a registered JWT, current identity, portal and job permissions.
COMMIT;

SELECT count(*) FILTER(WHERE auth_user_id IS NOT NULL) AS linked_profiles,
       count(*) FILTER(WHERE auth_user_id IS NULL) AS profiles_waiting_for_import
FROM public.users;
