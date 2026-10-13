-- Read-only health checks; no passwords, tokens, payloads, or account details returned.
SELECT c.relname AS table_name, c.relrowsecurity AS rls_enabled,
       has_table_privilege('anon',c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS anon_access,
       has_table_privilege('authenticated',c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS authenticated_access,
       has_table_privilege('service_role',c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') AS service_role_access
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r' ORDER BY c.relname;

SELECT p.sku,p.stock,COALESCE(sum(b.quantity) FILTER(WHERE b.deleted_at IS NULL),0) AS allocated_batch_stock,
       p.stock-COALESCE(sum(b.quantity) FILTER(WHERE b.deleted_at IS NULL),0) AS difference
FROM public.products p LEFT JOIN public.batches b ON b.product_id=p.id
WHERE p.deleted_at IS NULL GROUP BY p.id ORDER BY p.sku;

SELECT 'canonical_email_duplicates' AS check_name,count(*) AS violations
FROM (SELECT lower(btrim(email)) FROM public.users GROUP BY lower(btrim(email)) HAVING count(*)>1) d
UNION ALL
SELECT 'noncanonical_emails',count(*) FROM public.users WHERE email<>lower(btrim(email))
UNION ALL
SELECT 'cross_product_movements',count(*) FROM public.inventory_movements m
LEFT JOIN public.batches b ON b.id=m.batch_id LEFT JOIN public.batches d ON d.id=m.destination_batch_id
WHERE b.product_id<>m.product_id OR d.product_id<>m.product_id
UNION ALL
SELECT 'cross_product_origins',count(*) FROM public.batches b JOIN public.batches o ON o.id=b.origin_batch_id
WHERE o.product_id<>b.product_id OR o.id=b.id
UNION ALL
SELECT 'self_review',count(*) FROM public.inventory_adjustments WHERE reviewed_by=requested_by
UNION ALL
SELECT 'orphan_sessions',count(*) FROM public.sessions s LEFT JOIN public.users u ON u.id=s.user_id WHERE s.user_id IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'orphan_or_unsupported_tokens',count(*) FROM public.personal_access_tokens t LEFT JOIN public.users u ON u.id=t.tokenable_id WHERE u.id IS NULL OR t.tokenable_type<>'App\Models\User'
UNION ALL
SELECT 'orphan_or_unsupported_notifications',count(*) FROM public.notifications t LEFT JOIN public.users u ON u.id=t.notifiable_id WHERE u.id IS NULL OR t.notifiable_type<>'App\Models\User';

SELECT rel.relname AS table_name,c.conname,c.contype,c.convalidated,pg_get_constraintdef(c.oid) AS definition
FROM pg_constraint c JOIN pg_class rel ON rel.oid=c.conrelid JOIN pg_namespace n ON n.oid=rel.relnamespace
WHERE n.nspname='public' AND (c.conname LIKE 'wba_%' OR rel.relname IN ('batches','inventory_movements','inventory_adjustments'))
ORDER BY rel.relname,c.conname;

SELECT count(*) AS pending_requests_without_stock_version
FROM public.inventory_adjustments WHERE status='Pending' AND stock_version IS NULL;
