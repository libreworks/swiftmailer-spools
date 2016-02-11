<?php
require_once "MongoTestCase.php";

/**
 * Generated by PHPUnit_SkeletonGenerator on 2016-02-10 at 13:35:54.
 *
 * @requires extension mongodb
 */
class Swift_MongoDbSpoolTest extends MongoTestCase
{
    public function setUp()
    {
        $this->manager = new \MongoDB\Driver\Manager($this->getUri());
        $this->manager->executeCommand(
            $this->getDatabaseName(),
            new \MongoDB\Driver\Command(['dropDatabase' => 1])
        );
    }

    /**
     * @covers Swift_MongoDbSpool::__construct
     * @covers Swift_MongoDbSpool::isStarted
     */
    public function testIsStarted()
    {
        $object = new Swift_MongoDbSpool($this->manager, $this->getNamespace());
        $this->assertTrue($object->isStarted());
    }

    /**
     * @covers Swift_MongoDbSpool::start
     * @covers Swift_MongoDbSpool::stop
     */
    public function testStart()
    {
        $object = new Swift_MongoDbSpool($this->manager, $this->getNamespace());
        // basically a no-op, just doing this for code coverage
        $object->start();
        $object->stop();
    }

    /**
     * @covers Swift_MongoDbSpool::__construct
     * @covers Swift_MongoDbSpool::checkBlank
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage This parameter cannot be blank
     */
    public function testConstructBlank()
    {
        new Swift_MongoDbSpool($this->manager, "   ");
    }

    /**
     * @covers Swift_MongoDbSpool::queueMessage
     * @covers Swift_MongoDbSpool::write
     */
    public function testQueueMessage()
    {
        $body = 'Here is the message itself';
        $message = Swift_Message::newInstance()
          ->setSubject('Your subject')
          ->setFrom(array('john@doe.com' => 'John Doe'))
          ->setTo(array('receiver@domain.org'))
          ->setBody($body);
        
        $object = new Swift_MongoDbSpool($this->manager, $this->getNamespace());
        $this->assertTrue($object->queueMessage($message));
        
        $query = new \MongoDB\Driver\Query(array('message' => array('$ne' => null)));
        $good = false;
        foreach ($this->manager->executeQuery($this->getNamespace(), $query) as $row) {
            if ($row->message instanceof \MongoDB\BSON\Binary) {
                $this->assertEquals($message, unserialize($row->message->getData()));
                $good = true;
            }
        }
        $this->assertTrue($good, "No matching message found");
    }

    /**
     * @covers Swift_MongoDbSpool::recover
     * @covers Swift_MongoDbSpool::write
     */
    public function testRecover()
    {
        $body = 'Here is the message itself';
        $message1 = Swift_Message::newInstance()
          ->setSubject('Your subject 1')
          ->setFrom(array('john@doe.com' => 'John Doe'))
          ->setTo(array('receiver@domain.org'))
          ->setBody($body . ' 1');
        $message2 = Swift_Message::newInstance()
          ->setSubject('Your subject 2')
          ->setFrom(array('john@doe.com' => 'John Doe'))
          ->setTo(array('receiver@domain.org'))
          ->setBody($body . ' 2');
        
        $bulk = new \MongoDB\Driver\BulkWrite(array('ordered' => true));
        $bulk->insert(array('message' => serialize($message1), 'sentOn' => new \MongoDB\BSON\UTCDateTime(microtime(true) * 1000 - 920000)));
        $bulk->insert(array('message' => serialize($message2), 'sentOn' => new \MongoDB\BSON\UTCDateTime(microtime(true) * 1000 - 300000)));
        $wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
        $this->manager->executeBulkWrite($this->getNamespace(), $bulk, $wc);
        
        $object = new Swift_MongoDbSpool($this->manager, $this->getNamespace(), null, $wc);
        $object->recover();
        
        $this->assertEquals(1, $this->docount(array('sentOn' => null)));
        $this->assertEquals(1, $this->docount(array('sentOn' => array('$ne' => null))));
    }

    /**
     * @covers Swift_MongoDbSpool::flushQueue
     * @covers Swift_MongoDbSpool::find
     * @covers Swift_MongoDbSpool::delete
     * @covers Swift_MongoDbSpool::write
     * @covers Swift_MongoDbSpool::setSentOn
     * @covers Swift_MongoDbSpool::now
     */
    public function testFlushQueue()
    {
        $body = 'Here is the message itself';
        $message1 = Swift_Message::newInstance()
          ->setSubject('Your subject 1')
          ->setFrom(array('john@doe.com' => 'John Doe'))
          ->setTo(array('receiver@domain.org'))
          ->setBody($body . ' 1');
        $message2 = Swift_Message::newInstance()
          ->setSubject('Your subject 2')
          ->setFrom(array('john@doe.com' => 'John Doe'))
          ->setTo(array('receiver@domain.org'))
          ->setBody($body . ' 2');
        
        $bulk = new \MongoDB\Driver\BulkWrite(array('ordered' => true));
        $bulk->insert(array('message' => new \MongoDB\BSON\Binary(serialize($message1), \MongoDB\BSON\Binary::TYPE_GENERIC), 'sentOn' => null));
        $bulk->insert(array('message' => null, 'sentOn' => null));
        $bulk->insert(array('message' => new \MongoDB\BSON\Binary(serialize($message2), \MongoDB\BSON\Binary::TYPE_GENERIC), 'sentOn' => null));
        $bulk->insert(array('message' => 'hi', 'sentOn' => null));
        $wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
        $this->manager->executeBulkWrite($this->getNamespace(), $bulk, $wc);
        
        $object = new Swift_MongoDbSpool($this->manager, $this->getNamespace(), null, $wc);
        $failed = [];
        
        $transport = $this->getMockBuilder('Swift_Transport')->getMock();
        $transport->method('isStarted')->willReturn(true);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                array($this->equalTo($message1), $this->equalTo($failed)),
                array($this->equalTo($message2), $this->equalTo($failed))
            )->willReturn(1);

        $this->assertEquals(4, $this->docount([]));

        $this->assertEquals(2, $object->flushQueue($transport, $failed));
        
        $this->assertEquals(2, $this->docount([]));
        
        $this->verifyMockObjects();
    }
    
    protected function docount(array $query)
    {
        $cursor = $this->manager->executeCommand(
            $this->getDatabaseName(),
            new \MongoDB\Driver\Command(
                array('count' => $this->getCollectionName(), 'query' => $query)
            )
        );
        $result = current($cursor->toArray());
        return (int) $result->n;
    }
}
