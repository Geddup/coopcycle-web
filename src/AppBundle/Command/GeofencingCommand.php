<?php

namespace AppBundle\Command;

use AppBundle\Entity\Task;
use AppBundle\Entity\Sylius\Order;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client as Redis;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GeofencingCommand extends Command
{
    private $doctrine;
    private $tile38;
    private $io;

    public function __construct(EntityManagerInterface $doctrine, Redis $tile38)
    {
        $this->doctrine = $doctrine;
        $this->tile38 = $tile38;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('coopcycle:geofencing')
            ->setDescription('Subscribe to Tile38 geofencing channels');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $taskRepository = $this->doctrine->getRepository(Task::class);
        $orderRepository = $this->doctrine->getRepository(Order::class);

        $messageCount = 0;

        // @see https://github.com/nrk/predis/blob/v1.1/examples/pubsub_consumer.php

        $pubsub = $this->tile38->pubSubLoop();

        $pubsub->psubscribe('coopcycle:dropoff:*');

        foreach ($pubsub as $message) {

            switch ($message->kind) {

                case 'psubscribe':
                    $this->io->text(sprintf('Subscribed to channels matching "%s"', $message->channel));
                    break;

                case 'pmessage':

                    // (
                    //     [kind] => pmessage
                    //     [channel] => coopcycle:dropoff:7395
                    //     [payload] => {
                    //         "command":"set",
                    //         "group":"5e78da00fdee2e0001356871",
                    //         "detect":"enter",
                    //         "hook":"coopcycle:dropoff:7395",
                    //         "key":"coopcycle:fleet",
                    //         "time":"2020-03-23T15:47:12.1482893Z",
                    //         "id":"bot_2",
                    //         "object":{"type":"Point","coordinates":[2.3184081,48.8554067]}
                    //     }
                    // )

                    $this->io->text(sprintf('Received pmessage on channel "%s"', $message->channel));

                    $payload = json_decode($message->payload, true);

                    preg_match('/^coopcycle:dropoff:([0-9]+)$/', $payload['hook'], $matches);

                    $taskId = (int) $matches[1];

                    $task = $taskRepository->find($taskId);
                    if ($order = $orderRepository->findOneByTask($task)) {

                    }

                    // TODO Send notification/SMS

                    ++$messageCount;

                    if ($messageCount >= 3) {
                        $this->io->text(sprintf('Unsubscribing after processing %d messages', $messageCount));
                        $pubsub->punsubscribe('coopcycle:dropoff:*');
                    }

                    break;
            }
        }

        // Always unset the pubsub consumer instance when you are done! The
        // class destructor will take care of cleanups and prevent protocol
        // desynchronizations between the client and the server.
        unset($pubsub);

        $this->io->text('Consumer exited');

        return 0;
    }
}
