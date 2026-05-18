<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_token', schema: 'users')]
class RefreshToken extends BaseRefreshToken
{
}
