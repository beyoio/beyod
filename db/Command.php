<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */
namespace beyod\db;

/**
 * Command prevent mysql has gone away:
 * ```
 * 'components'=>[
 *      'db'=>[
 *          'class' => 'yii\db\Connection',
 *          'commandClass' => 'beyod\db\Command',
 *      ],
 * ]
 *```
 */

class Command extends \yii\db\Command
{
    public function execute()
    {
        try {
            return parent::execute();
        } catch (\Exception $e) {
            if(($e instanceof \PDOException) || ($e instanceof \yii\db\Exception) && 
               isset($e->errorInfo[1]) && in_array($e->errorInfo[1], [2006, 2013])
            )
            {
                $this->db->close();
                $this->db->open();
                $this->pdoStatement = null ;
                return parent::execute();
            }
            
            throw $e;
        }
    }
    
    
    protected function queryInternal($method, $fetchMode = null){
        try {
            return parent::queryInternal($method, $fetchMode);
        } catch (\Exception $e) {
            if(($e instanceof \PDOException) || ($e instanceof \yii\db\Exception) &&
                isset($e->errorInfo[1]) && in_array($e->errorInfo[1], [2006, 2013])
            )
            {
                $this->db->close();
                $this->db->open();
                $this->pdoStatement = null ;
                return parent::queryInternal($method, $fetchMode);
            }
            
            throw $e;
        }
    }
    
}
