<?php
use Predis\Command\Command as RedisCommand;

class FtDropIndex extends RedisCommand
{
    public function getId()
    {
        return 'FT.DROPINDEX';
    }
}
