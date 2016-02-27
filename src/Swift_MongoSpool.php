<?php
/**
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */

/**
 * A Swift spool that uses legacy Mongo drivers (http://docs.php.net/manual/en/book.mongo.php)
 *
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */
class Swift_MongoSpool extends Swift_ConfigurableSpool
{
    /**
     * @var \MongoCollection The collection of queued emails
     */
    protected $collection;
    
    /**
     * Creates a new MongoSpool
     *
     * @param \MongoCollection $collection The collection for messages
     */
    public function __construct(\MongoCollection $collection)
    {
        $this->collection = $collection;
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
     * @param Swift_Mime_Message $message The message to store
     * @return bool
     * @throws Swift_IoException
     */
    public function queueMessage(Swift_Mime_Message $message)
    {
        try {
            $doc = array(
                'message' => new \MongoBinData(serialize($message), 0)
            );
            $this->collection->insert($doc);
            return true;
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not write to database", 0, $e);
        }
    }

    /**
     * Execute a recovery if for any reason a process is sending for too long.
     *
     * @param int $timeout in second Defaults is for very slow smtp responses
     * @throws Swift_IoException
     */
    public function recover($timeout = 900)
    {
        $this->write(
            array('sentOn' => array('$lte' => new \MongoDate(time() - $timeout))),
            array('$set' => array('sentOn' => null)),
            array('multiple' => true)
        );
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param Swift_Transport $transport        A transport instance
     * @param string[]        $failedRecipients An array of failures by-reference
     * @return int The number of sent e-mails
     * @throws Swift_IoException
     */
    public function flushQueue(Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted()) {
            $transport->start();
        }
        $limit = $this->getMessageLimit();
        $results = $this->collection->find(array('sentOn' => null));
        if ($limit > 0) {
            $results->limit($limit);
        }
        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();
        $timeLimit = $this->getTimeLimit();
        foreach ($results as $result) {
            if (!isset($result['message']) || !($result['message'] instanceof \MongoBinData)) {
                continue;
            }
            $id = $result['_id'];
            $this->write(
                array('_id' => $id),
                array('$set' => array('sentOn' => new \MongoDate()))
            );
            $message = unserialize($result['message']->bin);
            $count += $transport->send($message, $failedRecipients);
            try {
                $this->collection->remove(array('_id' => $id));
            } catch (\Exception $e) {
                throw new Swift_IoException("Could not update email sent on date", 0, $e);
            }            
            if ($timeLimit && (time() - $time) >= $timeLimit) {
                break;
            }
        }
        return $count;
    }

    /**
     * Executes a bulk write to MongoDB, wrapping exceptions.
     *
     * @param array $query
     * @param array $set
     * @param arary $options
     * @throws Swift_IoException if things go wrong
     */
    protected function write(array $query, array $set, array $options = array())
    {
        try {
            return $this->collection->update($query, $set, $options);
        } catch (\Exception $e) {
            throw new Swift_IoException("Could not write to database", 0, $e);
        }
    }
}
