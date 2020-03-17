<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\LoopEat\Client as LoopEatClient;
use AppBundle\Sylius\Order\OrderInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Validation;

class LoopEatBalanceValidator extends ConstraintValidator
{
    private $client;

    public function __construct(
        LoopEatClient $client,
        LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function validate($object, Constraint $constraint)
    {
        if (!$object instanceof OrderInterface) {
            throw new \InvalidArgumentException(sprintf('$object should be an instance of "%s"', OrderInterface::class));
        }

        $restaurant = $object->getRestaurant();

        if (null === $restaurant) {
            return;
        }

        if (!$restaurant->isLoopeatEnabled()) {
            return;
        }

        $quantity = $object->getReusablePackagingQuantity();

        if ($quantity < 1) {
            return;
        }

        try {

            $currentCustomer = $this->client->currentCustomer($object->getCustomer());
            $balance = $currentCustomer['loopeatBalance'];

            if ($balance < $quantity) {
                $this->context->buildViolation($constraint->insufficientBalance)
                    ->atPath('reusablePackagingEnabled')
                    ->addViolation();
            }

        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());
        }

    }
}
