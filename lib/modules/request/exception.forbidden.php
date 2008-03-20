<?php
// vim: expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class ForbiddenException extends UserErrorException
{
  public function __construct($description = null)
  {
    if (null === $description)
      $description = t("Ваших полномочий недостаточно для выполнения запрошенной операции.");

    if ('anonymous' == mcms::user()->getName())
      throw new UnauthorizedException($description);

    parent::__construct(t("Нет доступа"), 403, $description);
  }
};
