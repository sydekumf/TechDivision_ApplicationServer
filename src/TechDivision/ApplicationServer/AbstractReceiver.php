<?php

/**
 * TechDivision\ApplicationServer\AbstractReceiver
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 */
namespace TechDivision\ApplicationServer;

use TechDivision\ApplicationServer\InitialContext;
use TechDivision\ApplicationServer\Interfaces\ReceiverInterface;

/**
 *
 * @package TechDivision\ApplicationServer
 * @copyright Copyright (c) 2010 <info@techdivision.com> - TechDivision GmbH
 * @license http://opensource.org/licenses/osl-3.0.php
 *          Open Software License (OSL 3.0)
 * @author Tim Wagner <tw@techdivision.com>
 * @author Johann Zelger <jz@techdivision.com>
 */
abstract class AbstractReceiver implements ReceiverInterface
{

    /**
     * The container instance.
     *
     * @var \TechDivision\ApplicationServer\Interfaces\ContainerInterface
     */
    protected $container;

    /**
     * The worker type to use.
     *
     * @var string
     */
    protected $workerType;

    /**
     * The thread type to use.
     *
     * @var string
     */
    protected $threadType;
    
    /**
     * The actual number of workers running.
     * 
     * @var integer
     */
    protected $workerCounter = 0;
    
    /**
     * The array containing the running worker.
     * 
     * @var array
     */
    protected $worker = array();

    /**
     * @var InitialContext
     */
    protected $initialContext;

    /**
     * Sets the reference to the container instance.
     *
     * @param \TechDivision\ApplicationServer\InitialContext $initialContext The initial context instance
     * @param \TechDivision\ApplicationServer\Interfaces\ContainerInterface $container
     *            The container instance
     */
    public function __construct($initialContext, $container)
    {

        // initialize the initial context
        $this->initialContext = $initialContext;

        // set the container instance
        $this->container = $container;

        // enable garbage collector
        $this->gcEnable();
    }

    /**
     * Returns the resource class used to create a new socket.
     *
     * @return string The resource class name
     */
    protected abstract function getResourceClass();

    /**
     *
     * @see TechDivision\ApplicationServer\Interfaces\ReceiverInterface::start()
     */
    public function start()
    {
        try {

            /**
             * @var \TechDivision\Socket\Client $socket
             */
            $socket = $this->newInstance($this->getResourceClass());

            // prepare the main socket and listen
            $socket->setAddress($this->getAddress())
                ->setPort($this->getPort())
                ->start();
            
            // check if resource been initiated
            if ($resource = $socket->getResource()) {
                // open threads where accept connections
                while ($workerCounter ++ < $this->getWorkerNumber()) {
                    // init thread
                    $this->worker[$workerCounter] = $this->newWorker($socket->getResource());
                    // start thread async
                    $this->worker[$workerCounter]->start();
                }
            }

            // log a message that the container has been started successfully
            $this->getInitialContext()->getSystemLogger()->info(
                sprintf('Successfully started receiver for container %s, listening on IP: %s Port: %s Number of workers started: %s, Workertype: %s',
                $this->getContainer()->getContainerNode()->getName(), $this->getAddress(), $this->getPort(),
                $this->getWorkerNumber(), $this->getWorkerType()));


            // collect garbage and free memory/sockets
            while (true) {
                
                // make sure that the number of configured workers are running
                for ($i = 0; $i < sizeof($this->worker); $i++) {
                    
                    // if not, start a new worker
                    if ($this->worker[$i] != null && $this->worker[$i]->isRunning() === false) {
                        // unset the worker and free memory and sockets
                        unset($this->worker[$i]);
                        // init thread
                        $this->worker[$i] = $this->newWorker($socket->getResource());
                        // start thread async
                        $this->worker[$i]->start();
                    }
                }
                
                // sleep for 0.1 seconds to lower system load
                usleep(100000);
            }
            
            // wait till all workers have been finished
            foreach ($this->worker as $worker) {
                $worker->join(); 
            }
            
        } catch (\Exception $e) {
            $this->getInitialContext()->getSystemLogger()->error($e->__toString());
        }

        if (is_resource($resource)) {
            $socket->close();
        }

        return false;
    }

    /**
     * Returns the refrence to the container instance.
     *
     * @return \TechDivision\ApplicationServer\Interfaces\ContainerInterface The container instance
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     *
     * @see \TechDivision\ApplicationServer\Interfaces\ReceiverInterface::getWorkerNumber()
     */
    public function getWorkerNumber()
    {
        return $this->getContainer()->getContainerNode()->getReceiver()->getParam('workerNumber');
    }

    /**
     *
     * @see \TechDivision\ApplicationServer\Interfaces\ReceiverInterface::getAddress()
     */
    public function getAddress()
    {
        return $this->getContainer()->getContainerNode()->getReceiver()->getParam('address');
    }

    /**
     *
     * @see \TechDivision\ApplicationServer\Interfaces\ReceiverInterface::getPort()
     */
    public function getPort()
    {
        return $this->getContainer()->getContainerNode()->getReceiver()->getParam('port');
    }

    /**
     * Return's the class name of the receiver's worker type.
     *
     * @return string The class name of the receiver's worker type
     */
    public function getWorkerType()
    {
        return $this->getContainer()->getContainerNode()->getReceiver()->getWorker()->getType();
    }

    /**
     * Return's the class name of the receiver's thread type.
     *
     * @return string The class name of the receiver's thread type
     */
    public function getThreadType()
    {
        return $this->getContainer()->getContainerNode()->getReceiver()->getThread()->getType();
    }

    /**
     * Forces collection of any existing garbage cycles.
     *
     * @return integer The number of collected cycles
     * @link http://php.net/manual/en/features.gc.collecting-cycles.php
     */
    public function gc()
    {
        return gc_collect_cycles();
    }

    /**
     * Returns TRUE if the PHP internal garbage collection is enabled.
     *
     * @return boolean TRUE if the PHP internal garbage collection is enabled
     * @link http://php.net/manual/en/function.gc-enabled.php
     */
    public function gcEnabled()
    {
        return gc_enabled();
    }

    /**
     * Enables PHP internal garbage collection.
     *
     * @return \TechDivision\PersistenceContainer\Container The container instance
     * @link http://php.net/manual/en/function.gc-enable.php
     */
    public function gcEnable()
    {
        gc_enable();
        return $this;
    }

    /**
     * Disables PHP internal garbage collection.
     *
     * @return \TechDivision\PersistenceContainer\Container The container instance
     * @link http://php.net/manual/en/function.gc-disable.php
     */
    public function gcDisable()
    {
        gc_disable();
        return $this;
    }

    /**
     * Returns a thread
     *
     * @return \Thread The request acceptor thread
     */
    public function newWorker($socketResource)
    {
        $params = array(
            $this->getInitialContext(),
            $this->getContainer(),
            $socketResource,
            $this->getThreadType()
        );
        return $this->newInstance($this->getWorkerType(), $params);
    }

    /**
     * (non-PHPdoc)
     *
     * @see \TechDivision\ApplicationServer\InitialContext::newInstance()
     */
    public function newInstance($className, array $args = array())
    {
        return $this->getInitialContext()->newInstance($className, $args);
    }

    /**
     * (non-PHPdoc)
     *
     * @see \TechDivision\ApplicationServer\InitialContext::newService()
     */
    public function newService($className)
    {
        return $this->getInitialContext()->newService($className);
    }

    /**
     * Returns the inital context instance.
     *
     * @return \TechDivision\ApplicationServer\InitialContext The initial context instance
     */
    public function getInitialContext()
    {
        return $this->initialContext;
    }
}