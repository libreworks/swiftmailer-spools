<?php
/**
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */

/**
 * A Swift spool that uses PDO.
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */
class Swift_PdoSpool extends Swift_ConfigurableSpool
{
    /**
     * @var \PDO The database driver
     */
    protected $pdo;
    protected $table;
    protected $pkey;
    protected $messageField;
    protected $timeField;

    /**
     * Creates a new PdoSpool
     *
     * @param \PDO $pdo The database driver
     * @param string $table The table name
     * @param string $pkey The primary key field
     * @param string $messageField The field to add serialized message data
     * @param string $timeField The integer field to store message timestamp
     */
    public function __construct(\PDO $pdo, $table, $pkey, $messageField, $timeField)
    {
        $this->pdo = $pdo;
        $this->table = $this->checkBlank($table);
        $this->pkey = $this->checkBlank($pkey);
        $this->messageField = $this->checkBlank($messageField);
        $this->timeField = $this->checkBlank($timeField);
    }

    private function checkBlank($value)
    {
        $value = trim($value);
        if (strlen($value) == 0) {
            throw new \InvalidArgumentException("This parameter cannot be blank");
        }
        return $value;
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return bool
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
    }

    /**
     * Queues a message.
     *
     * @param Swift_Mime_SimpleMessage $message The message to store
     *
     * @throws Swift_IoException
     *
     * @return bool
     */
    public function queueMessage(Swift_Mime_SimpleMessage $message)
    {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO " . $this->table . " ("
                . $this->messageField . ") VALUES (?)");
            $stmt->bindValue(1, serialize($message), PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not enqueue message", 0, $e);
        }
    }

    /**
     * Execute a recovery if for any reason a process is sending for too long.
     *
     * @param int $timeout in second Defaults is for very slow smtp responses
     */
    public function recover($timeout = 900)
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE ' . $this->table . ' SET '
                . $this->timeField . ' = NULL WHERE ' . $this->timeField . ' <= ?');
            $stmt->bindValue(1, time() - 900, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not update email sent on date", 0, $e);
        }
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param Swift_Transport $transport        A transport instance
     * @param string[]        $failedRecipients An array of failures by-reference
     *
     * @return int The number of sent e-mails
     */
    public function flushQueue(Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted()) {
            $transport->start();
        }
        $limit = $this->getMessageLimit();
        $limit = $limit > 0 ? $limit : null;
        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();
        $timeLimit = $this->getTimeLimit();
        try {
            $results = $this->pdo->query('SELECT ' . $this->pkey . ' as pkey, '
                . $this->messageField . ' as email FROM ' . $this->table . ' WHERE '
                . $this->timeField . ' IS NULL');
            $ustmt = $this->pdo->prepare('UPDATE ' . $this->table . ' SET '
                . $this->timeField . ' = ? WHERE ' . $this->pkey . ' = ?');
            $dstmt = $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE '
                . $this->pkey . ' = ?');
            foreach ($results->fetchAll() as $result) {
                $id = $result[0];
                $ustmt->execute(array(time(), $result[0]));
                $message = unserialize($result[1]);
                $count += $transport->send($message, $failedRecipients);
                $dstmt->execute(array($id));
                if ($limit && $count >= $limit) {
                    break;
                }
                if ($timeLimit && (time() - $time) >= $timeLimit) {
                    break;
                }
            }
            return $count;
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not access database", 0, $e);
        }
    }
}
