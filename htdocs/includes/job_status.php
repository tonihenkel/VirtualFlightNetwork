<?php
function vfnRecordJobStatus(PDO $pdo, string $jobKey, bool $success, string $details=''): void
{
    try {
        $sql = $success
            ? "INSERT INTO system_job_status(job_key,last_started_at,last_success_at,last_error,details_json) VALUES(:job,NOW(),NOW(),NULL,:details) ON DUPLICATE KEY UPDATE last_started_at=NOW(),last_success_at=NOW(),last_error=NULL,details_json=VALUES(details_json)"
            : "INSERT INTO system_job_status(job_key,last_started_at,last_error_at,last_error) VALUES(:job,NOW(),NOW(),:details) ON DUPLICATE KEY UPDATE last_started_at=NOW(),last_error_at=NOW(),last_error=VALUES(last_error)";
        $pdo->prepare($sql)->execute(['job'=>$jobKey,'details'=>mb_substr($details,0,2000)]);
    } catch (Throwable $ignored) {
        // Monitoring must never break the actual data endpoint.
    }
}
