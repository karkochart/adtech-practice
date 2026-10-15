<?php

namespace App\Exceptions;

/** Списание превысило бы дневной бюджет кампании. */
class BudgetExceededException extends \DomainException
{
}
