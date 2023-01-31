<?php

Class ControlQueue {
    protected $registry;
    protected static $instance;

    protected function __construct(){
        $this->registry = new SplQueue();
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
        // late static binding
            self::$instance = new self;
            
        }
        return self::$instance;
    }

    public function addRegistryItem(string $item):void{
        $this->registry->enqueue($item);
    }

    public function getRegistryQueue(): SplQueue{
        return $this->registry;
    }

    public function popTopRegistryItem(): void{
        $this->registry->dequeue();
    }

}


Class ControlQueueTicket extends ControlQueue {

}

Class ControlQueueProblem extends ControlQueue {

}

Class ControlQueueChange extends ControlQueue{

}

Class ControlQueueTicketRec extends ControlQueue{
    
}








/*Class ControlQueuesTickets {

    private $registry_tickets;
    private static $instance;

    protected function __construct()
    {
        $this->registry_tickets = new SplQueue();
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
        // late static binding
            self::$instance = new self;

            Toolbox::logInFile(
                'event_add_item',
                sprintf(
                    __('%1$s: %2$s'),
                    basename(__FILE__,'.php'),
                    sprintf(
                        __('---Se inició la instancia singleton QueueTicket---') . "\n"
                    )
                )
            );
        }
        return self::$instance;
    }

    //GETTERS AND SETTERS
    public function addRegistryTicket(string $item):void{
        $this->registry_tickets->enqueue($item);
    }

    public function getRegistryTickets(): SplQueue{
        return $this->registry_tickets;
    }

    public  function popTopRegistryTicket(): void{
        $this->registry_tickets->dequeue();
    }

    

}*/