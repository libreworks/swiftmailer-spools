<?php
/**
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */

/**
 * A Swift spool that uses new MongoDB Drivers (http://docs.php.net/set.mongodb)
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */
class Swift_MongoDbSpool extends Swift_ConfigurableSpool
{
    /**
     * @var \MongoDB\Driver\Manager
     */
    private $manager;
    /**
     * @var string
     */
    private $collection;
    /**
     * @var \MongoDB\Driver\ReadPreference
     */
    private $rp;
    /**
     * @var MongoDB\Driver\WriteConcern
     */
    private $wc;

    private static $limit1 = array('limit' => 1);

    /**
     * Creates a new MongoDB spool.
     *
     * @param \MongoDB\Driver\Manager $manager The manager
     * @param string $collection The collection name (e.g. "mydb.emails")
     * @param \MongoDB\Driver\ReadPreference $rp Optional read preference
     * @param \MongoDB\Driver\WriteConcern $wc Optional write concern
     */
    public function __construct(\MongoDB\Driver\Manager $manager, $collection, \MongoDB\Driver\ReadPreference $rp = null, \MongoDB\Driver\WriteConcern $wc = null)
    {
        $this->manager = $manager;
        $this->collection = $this->checkBlank($collection);
        $this->rp = $rp;
        $this->wc = $wc;
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
     * @throws Swift_IoException
     * @return bool
     */
    public function queueMessage(Swift_Mime_SimpleMessage $message)
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->insert(array(
            'message' => new \MongoDB\BSON\Binary(serialize($message), \MongoDB\BSON\Binary::TYPE_GENERIC)
        ));
        $this->write($bulk);
        return true;
    }

    /**
     * Execute a recovery if for any reason a process is sending for too long.
     *
     * @param int $timeout in second Defaults is for very slow smtp responses
     * @throws Swift_IoException
     */
    public function recover($timeout = 900)
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(
            array('sentOn' => array('$lte' => $this->now(0 - ($timeout * 1000)))),
            array('$set' => array('sentOn' => null)),
            array('multi' => true)
        );
        $this->write($bulk);
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param Swift_Transport $transport        A transport instance
     * @param string[]        $failedRecipients An array of failures by-reference
     * @throws Swift_IoException
     * @return int The number of sent e-mails
     */
    public function flushQueue(Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted()) {
            $transport->start();
        }
        $limit = $this->getMessageLimit();
        $results = $this->find(array('sentOn' => null), $limit > 0 ? array('limit' => $limit) : array());
        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();
        $timeLimit = $this->getTimeLimit();
        foreach ($results as $result) {
            if (!isset($result->message) || !($result->message instanceof \MongoDB\BSON\Binary)) {
                continue;
            }
            $id = $result->_id;
            $this->setSentOn($id, $this->now());
            $count += $transport->send(unserialize($result->message->getData()), $failedRecipients);
            $this->delete($id);
            if ($timeLimit && (time() - $time) >= $timeLimit) {
                break;
            }
        }
        return $count;
    }

    /**
     * Performs a query.
     *
     * @param array $query The query to perform
     * @param array $options Optional array of query options
     * @return \MongoDB\Driver\Cursor The results cursor
     * @throws Swift_IoException
     */
    protected function find(array $query, array $options = array())
    {
        try {
            $q = new \MongoDB\Driver\Query($query, $options);
            return $this->manager->executeQuery($this->collection, $q, $this->rp);
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not query for emails", 0, $e);
        }
    }

    /**
     * Deletes a single record.
     *
     * @param \MongoDB\BSON\ObjectID $id The ID of the message to delete
     * @throws Swift_IoException
     */
    protected function delete(\MongoDB\BSON\ObjectID $id)
    {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->delete(array('_id' => $id), self::$limit1);
        $this->write($bulk);
    }

    /**
     * Sets a single record's sentOn field.
     *
     * @param \MongoDB\BSON\ObjectID $id The ID of the message to update
     * @param \MongoDB\BSON\UTCDateTime $time The time (or null) to set
     * @return \MongoDB\Driver\WriteResult the write result
     * @throws Swift_IoException
     */
    protected function setSentOn(\MongoDB\BSON\ObjectID $id, \MongoDB\BSON\UTCDateTime $time = null)
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(array('_id' => $id), array('$set' => array('sentOn' => $time)));
        $this->write($bulk);
    }

    /**
     * Executes a bulk write to MongoDB, wrapping exceptions.
     *
     * @param \MongoDB\Driver\BulkWrite $bulk
     * @return \MongoDB\Driver\WriteResult the write result
     * @throws Swift_IoException if things go wrong
     */
    protected function write(\MongoDB\Driver\BulkWrite $bulk)
    {
        try {
            return $this->manager->executeBulkWrite($this->collection, $bulk, $this->wc);
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not update email sent on date", 0, $e);
        }
    }

    /**
     * Gets the current date.
     *
     * @param int $offset Milliseconds offset
     * @return \MongoDB\BSON\UTCDateTime the current date
     */
    protected function now($offset = 0)
    {
        return new \MongoDB\BSON\UTCDateTime(microtime(true) * 1000 + $offset);
    }
}
