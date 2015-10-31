<?php
/**
 * 
 * @copyright 2015 LibreWorks contributors
 * @license   http://opensource.org/licenses/MIT MIT License
 */

/**
 * A Swift spool that uses MongoDB.
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
     *
     * @throws Swift_IoException
     *
     * @return bool
     */
    public function queueMessage(Swift_Mime_Message $message)
    {
        $this->collection->insert(array(
            'message' => new \MongoBinData(serialize($message), \MongoBinData::GENERIC)
        ));
        return true;
    }

    /**
     * Execute a recovery if for any reason a process is sending for too long.
     *
     * @param int $timeout in second Defaults is for very slow smtp responses
     */
    public function recover($timeout = 900)
    {
        $results = $this->collection->find(array('sentOn' => array('$ne' => null)));
        foreach ($results as $result) {
            $lockedtime = $result['sentOn']->sec;
            if ((time() - $lockedtime) > $timeout) {
                $this->collection->update(array('_id' => $result['_id']),
                    array('$set' => array('sentOn' => null)));
            }
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
            $this->collection->update(array('_id' => $id),
                array('$set' => array('sentOn' => new \MongoDate())));
            $message = unserialize($result['message']->bin);
            $count += $transport->send($message, $failedRecipients);
            $this->collection->remove(array('_id' => $id));
            if ($timeLimit && (time() - $time) >= $timeLimit) {
                break;
            }
        }
        return $count;
    }
}
