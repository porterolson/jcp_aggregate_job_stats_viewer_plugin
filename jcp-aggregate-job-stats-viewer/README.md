# JCP Aggregate Job Stats Viewer V2

Admin-only WordPress plugin that displays aggregate job response data collected by the JCP Session Tracker plugin.

## Install

1. Copy `jcp-aggregate-job-stats-viewer` into `wp-content/plugins/`.
2. Activate **JCP Aggregate Job Stats Viewer V2** in wp-admin.
3. Visit **Users -> Aggregate Job Stats**.

## Notes

- This plugin does not create, update, or delete job response rows.
- It reads the Session Tracker table named `{wpdb_prefix}jcpst_job_responses`.
- Users need the `list_users` capability to view the page.
