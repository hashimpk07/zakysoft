<?php 
use Predis\Command\Command as RedisCommand;

class FtCreate extends RedisCommand
{
    public function getId()
    {
        return 'FT.CREATE';
    }
}
