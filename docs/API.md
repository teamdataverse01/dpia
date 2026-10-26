# REST API (v1)

Base path `/api/v1`. JSON in, JSON out.

**Authentication:** every call needs a portal session (`AuthProvider`). Without one, the API returns 401.

**CSRF:** state-changing calls must send the `X-CSRF-Token` header. Get the token from `GET /api/v1/session`, or from the `<meta name="csrf-token">` tag on any page.

**Errors:**

```json
{ "error": { "code": "validation_failed", "message": "…", "fields": { "vendor_risk_ref": "…" } } }
```

| HTTP status | Meaning |
|---|---|
| 401 | No portal session |
| 403 | Role not allowed |
| 404 | Not found, or not visible to you |
| 409 | Not allowed in the current status |
| 419 | CSRF token missing or wrong |
| 422 | Validation failed |

Authorization is enforced server-side by `App\Services\Authorizer` for every endpoint.

| Method & path | Purpose | Notes |
|---|---|---|
| GET `/session` | Current user, roles, CSRF token | |
| POST `/risk-entities` | Create a vendor or DPIA | `entity_type`, `name`, `department_id` + type-specific fields (`service_description`… / `purpose`, `description`…) |
| GET `/risk-entities` | List | Filters: `type`, `department_id`, `rating`, `basis`, `status`, `owner`, `q` |
| GET `/risk-entities/{id}` | Full record | Factors, inherent breakdown, controls with unmet criteria, residual explanation, actions, approvals, approval round |
| PUT `/risk-entities/{id}/factors` | Save the factor panel, validate 7.1, recalculate | Body: `data_type[]`, option keys, `involves_data_sharing`, `vendor_risk_ref` |
| POST `/score-preview` | Live score without saving | Same body + `entity_type`, optional `risk_entity_id` |
| POST `/risk-entities/{id}/score` | Recalculate inherent + residual | Returns the explanation payload |
| GET `/risk-entities/{id}/recommended-controls` | Matched controls with `why_recommended` | |
| POST `/risk-entities/{id}/start-controls` | Create assessment rows for recommended controls | |
| PUT `/risk-entities/{id}/controls/{controlId}` | Record status / effectiveness / evidence metadata | |
| POST `/risk-entities/{id}/evidence` | Multipart: `control_id`, `evidence` file | Allow-listed types, 10 MB |
| POST `/risk-entities/{id}/controls/{controlId}/verify` | Reviewer verifies evidence | `{ "verified": false }` to undo |
| GET `/risk-entities/{id}/residual` | Rating, rule applied, cleared categories and why not, indicator score | |
| POST `/risk-entities/{id}/residual` | Confirm the residual (stage 6) | |
| POST `/risk-entities/{id}/confirm-negligible` | `justification`, optional `confirm:false` | Reviewer / Risk Approver; only from computed Low |
| POST `/risk-entities/{id}/submit` | Submit for approval | |
| POST `/risk-entities/{id}/approve` | `decision` = approved / rejected / risk_accepted, `justification`, `accepted_until` | Routed by residual tier |
| POST `/risk-entities/{id}/reassess` | Start a reassessment | |
| POST `/risk-entities/{id}/apply-template` | `template_id`, `mode` = accept / customise, `confirm_stale` | DPIA in draft/scored |
| POST / GET `/remediation-actions`, PATCH `/remediation-actions/{id}` | Manage actions | GET filters: `risk_entity_id`, `status` (incl. `overdue`, `open_all`), `department_id`, `entity_type`, `owner`, `priority` |
| GET `/controls`, POST `/controls`, PUT `/controls/{id}` | Control library | Writes are Admin only |
| GET `/activity-templates`, GET `/activity-templates/match`, POST `/activity-templates` | Search, match, save-as-template | Match by `department_id` + `activity_name`, and/or signatures (`data_type`, `data_subject_type`, `nature_of_processing`, `automated_decision_making`, `cross_border_transfer`, `third_party_transfer`) |
| GET `/dashboard/overview`, `/by-department`, `/remediation`, `/stories` | Dashboard | `basis=residual\|inherent`, `entity_type`, `department_id`, `from`, `to` |
| GET / PUT `/admin/scoring-config` | Admin | PUT creates a new version; weights must total 100 |
| GET / PUT `/admin/factor-options` | Admin | PUT `{ "options": [ { "id", "label", "help_text", "score", "sort_order" } ] }` |
| GET `/audit-log` | Auditor / Admin, read-only | Filters: `entity_table`, `entity_id`, `actor`, `action`, `from`, `to`, `limit`, `offset` |
