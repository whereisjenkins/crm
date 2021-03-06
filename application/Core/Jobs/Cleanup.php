<?php


namespace Core\Jobs;

use \Core\Core\Exceptions;

class Cleanup extends \Core\Core\Jobs\Base
{
    protected $cleanupJobPeriod = '1 month';

    protected $cleanupActionHistoryPeriod = '15 days';

    protected $cleanupAuthTokenPeriod = '1 month';

    protected $cleanupNotificationsPeriod = '2 months';

    protected $cleanupRemovedNotesPeriod = '2 months';

    protected $cleanupAttachmentsPeriod = '1 month';

    protected $cleanupRemindersPeriod = '15 days';

    public function run()
    {
        $this->cleanupJobs();
        $this->cleanupScheduledJobLog();
        $this->cleanupAttachments();
        $this->cleanupEmails();
        $this->cleanupNotes();
        $this->cleanupNotifications();
        $this->cleanupActionHistory();
        $this->cleanupAuthToken();
    }

    protected function cleanupJobs()
    {
        $query = "DELETE FROM `job` WHERE DATE(modified_at) < '".$this->getCleanupJobFromDate()."' AND status <> 'Pending'";

        $pdo = $this->getEntityManager()->getPDO();
        $sth = $pdo->prepare($query);
        $sth->execute();
    }

    protected function cleanupScheduledJobLog()
    {
        $pdo = $this->getEntityManager()->getPDO();

        $sql = "SELECT id FROM scheduled_job";
        $sth = $pdo->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $id = $row['id'];

            $lastRowsSql = "SELECT id FROM scheduled_job_log_record WHERE scheduled_job_id = '".$id."' ORDER BY created_at DESC LIMIT 0,10";
            $lastRowsSth = $pdo->prepare($lastRowsSql);
            $lastRowsSth->execute();
            $lastRowIds = $lastRowsSth->fetchAll(\PDO::FETCH_COLUMN, 0);

            $delSql = "DELETE FROM `scheduled_job_log_record`
                    WHERE scheduled_job_id = '".$id."'
                    AND DATE(created_at) < '".$this->getCleanupJobFromDate()."'
                    AND id NOT IN ('".implode("', '", $lastRowIds)."')
                ";
            $pdo->query($delSql);
        }
    }

    protected function cleanupActionHistory()
    {
        $period = '-' . $this->getConfig()->get('cleanupActionHistoryPeriod', $this->cleanupActionHistoryPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $query = "DELETE FROM `action_history_record` WHERE DATE(created_at) < '" . $datetime->format('Y-m-d') . "'";

        $pdo = $this->getEntityManager()->getPDO();
        $sth = $pdo->prepare($query);
        $sth->execute();
    }

    protected function cleanupReminders()
    {
        $period = '-' . $this->getConfig()->get('cleanupRemindersPeriod', $this->cleanupRemindersPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $query = "DELETE FROM `reminder` WHERE DATE(remind_at) < '" . $datetime->format('Y-m-d') . "'";

        $pdo = $this->getEntityManager()->getPDO();
        $sth = $pdo->prepare($query);
        $sth->execute();
    }

    protected function cleanupAuthToken()
    {
        $period = '-' . $this->getConfig()->get('cleanupAuthTokenPeriod', $this->cleanupAuthTokenPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $query = "DELETE FROM `auth_token` WHERE DATE(modified_at) < '" . $datetime->format('Y-m-d') . "' AND is_active = 0";

        $pdo = $this->getEntityManager()->getPDO();
        $sth = $pdo->prepare($query);
        $sth->execute();
    }

    protected function getCleanupJobFromDate()
    {
        $period = '-' . $this->getConfig()->get('cleanupJobPeriod', $this->cleanupJobPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);
        return $datetime->format('Y-m-d');
    }

    protected function cleanupAttachments()
    {
        $pdo = $this->getEntityManager()->getPDO();

        $period = '-' . $this->getConfig()->get('cleanupAttachmentsPeriod', $this->cleanupAttachmentsPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $collection = $this->getEntityManager()->getRepository('Attachment')->where(array(
            'OR' => array(
                array(
                    'role' => ['Export File']
                )
            ),
            'createdAt<' => $datetime->format('Y-m-d H:i:s')
        ))->limit(0, 1000)->find();

        foreach ($collection as $e) {
            $this->getEntityManager()->removeEntity($e);
        }

        if ($this->getConfig()->get('cleanupOrphanAttachments')) {
            $collection = $this->getEntityManager()->getRepository('Attachment')->where(array(
                array(
                    'role' => 'Attachment'
                ),
                'OR' => array(
                    array(
                        'parentId' => null,
                        'parentType!=' => null,
                        'relatedType=' => null
                    ),
                    array(
                        'parentType' => null,
                        'relatedId' => null,
                        'relatedType!=' => null
                    )
                ),
                'createdAt<' => $datetime->format('Y-m-d H:i:s'),
                'createdAt>' => '2017-05-10 00:00:00'
            ))->limit(0, 1000)->find();

            foreach ($collection as $e) {
                $this->getEntityManager()->removeEntity($e);
            }
        }

        $sql = "DELETE FROM attachment WHERE deleted = 1 AND created_at < ".$pdo->quote($datetime->format('Y-m-d H:i:s'));
        $sth = $pdo->query($sql);
    }

    protected function cleanupEmails()
    {
        $pdo = $this->getEntityManager()->getPDO();

        $dateBefore = date('Y-m-d H:i:s', time() - 3600 * 24 * 20);

        $sql = "SELECT * FROM email WHERE deleted = 1 AND created_at < ".$pdo->quote($dateBefore);
        $sth = $pdo->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $attachments = $this->getEntityManager()->getRepository('Attachment')->where(array(
                'parentId' => $id,
                'parentType' => 'Email'
            ))->find();
            foreach ($attachments as $attachment) {
                $this->getEntityManager()->removeEntity($attachment);
            }
            $sqlDel = "DELETE FROM email WHERE deleted = 1 AND id = ".$pdo->quote($id);
            $pdo->query($sqlDel);
            $sqlDel = "DELETE FROM email_user WHERE email_id = ".$pdo->quote($id);
            $pdo->query($sqlDel);
        }
    }

    protected function cleanupNotes()
    {
        $pdo = $this->getEntityManager()->getPDO();

        $period = '-' . $this->getConfig()->get('cleanupRemovedNotesPeriod', $this->cleanupRemovedNotesPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $sql = "SELECT * FROM `note` WHERE deleted = 1 AND DATE(created_at) < ".$pdo->quote($datetime->format('Y-m-d'));
        $sth = $pdo->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $attachments = $this->getEntityManager()->getRepository('Attachment')->where(array(
                'parentId' => $id,
                'parentType' => 'Note'
            ))->find();
            foreach ($attachments as $attachment) {
                $this->getEntityManager()->removeEntity($attachment);
            }
            $sqlDel = "DELETE FROM `note` WHERE deleted = 1 AND id = ".$pdo->quote($id);
            $pdo->query($sqlDel);
        }
    }

    protected function cleanupNotifications()
    {
        $pdo = $this->getEntityManager()->getPDO();

        $period = '-' . $this->getConfig()->get('cleanupNotificationsPeriod', $this->cleanupNotificationsPeriod);
        $datetime = new \DateTime();
        $datetime->modify($period);

        $sql = "SELECT * FROM `notification` WHERE DATE(created_at) < ".$pdo->quote($datetime->format('Y-m-d'));

        $sth = $pdo->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $this->getEntityManager()->getRepository('Notification')->deleteFromDb($id);
        }
    }
}

