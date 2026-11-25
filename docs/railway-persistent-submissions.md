# Railway persistent submission storage

Uploaded proposal and final-paper PDFs are private files. They are not stored in MySQL: `student_submissions.stored_filename` contains only the generated filename. The Docker build deliberately excludes `storage/submissions/*`, so `/var/www/html/storage/submissions` is ephemeral in Railway and is recreated empty after a deployment.

## Before changing Railway storage

1. Create and verify a completed Railway MySQL backup.
2. Do **not** redeploy or attach an empty volume before preserving any existing upload files. If the currently running deployment still has files, use a Railway shell to copy `/var/www/html/storage/submissions` to a secure backup location first.
3. If the current deployment already returns `Document unavailable`, inspect its shell before any further deployment. A database backup cannot restore PDFs because file bytes are not in MySQL. Recover missing files from a Railway deployment/volume backup or another known file backup; do not change submission rows to conceal the loss.

## Configure the Volume

1. Add a Railway Volume to the PHP service and mount it at `/data`.
2. Set the PHP service variable:

   ```text
   SUBMISSION_STORAGE_PATH=/data/submissions
   ```

3. Before enabling that variable in production, copy the preserved files into the mounted volume. From a Railway shell on the running service, after confirming both source and destination are present:

   ```sh
   mkdir -p /data/submissions
   cp -an /var/www/html/storage/submissions/. /data/submissions/
   find /data/submissions -maxdepth 1 -type f -name 'submission_*.pdf' -printf '%f %s bytes\n'
   ```

   Use the output only to compare filenames with `student_submissions.stored_filename`; never rename database rows or overwrite source PDFs.

4. Deploy the application update. The container creates the configured directory with private Apache access and writes all new uploads there.

## Verify safely

Use read-only SQL to inspect the affected record, without exposing data publicly:

```sql
SELECT id, project_id, student_id, stage, original_filename, stored_filename,
       mime_type, file_size, status, created_at
FROM student_submissions
WHERE id = 14 AND project_id = 5 AND stage = 'proposal';
```

In the service shell, verify the exact filename returned above is present at:

```text
/data/submissions/<stored_filename>
```

Then test as the assigned evaluator. The protected viewer authorizes the evaluator's assignment, checks the expected stage, verifies that the resolved file is a PDF, and streams it with `Content-Type: application/pdf` and `Content-Disposition: inline`.
