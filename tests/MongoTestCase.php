<?php
/**
 * Just common stuff for Mongo tests
 */
class MongoTestCase extends PHPUnit_Framework_TestCase
{
    /**
     * Return the connection URI.
     *
     * Borrowed from the mongo-php-library project's unit tests
     *
     * @return string
     */
    protected function getUri()
    {
        return getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017';
    }

    /**
     * Return the test collection name.
     *
     * Borrowed from the mongo-php-library project's unit tests
     *
     * @return string
     */
    protected function getCollectionName()
    {
         $class = new ReflectionClass($this);
         return sprintf('%s.%s', $class->getShortName(), hash('crc32b', $this->getName()));
    }

    /**
     * Return the test database name.
     *
     * Borrowed from the mongo-php-library project's unit tests
     *
     * @return string
     */
    protected function getDatabaseName()
    {
        return getenv('MONGODB_DATABASE') ?: 'swift_test';
    }

    /**
     * Return the test namespace.
     *
     * Borrowed from the mongo-php-library project's unit tests
     *
     * @return string
     */
    protected function getNamespace()
    {
         return sprintf('%s.%s', $this->getDatabaseName(), $this->getCollectionName());
    }
}
