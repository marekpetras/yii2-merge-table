<?php

/**
 * @author Marek Petras <mark@markpetras.eu>
 * @link https://github.com/marekpetras/yii2-merge-table/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @version 1.0.0
 */

namespace marekpetras\mergetable;

use Yii;
use Exception;

/**
 * MergeTableTrait provides the implementation multiple merge tables representing one whole set of data.
 *
 * Use individual table to access the partial data for better performance.
 *
 * Extends the functionality of active record by providing a set of methods for managing and selecting proper database tables
 *
 * The proper workflow should be very similar to regular models, with the exception that you have the possibility to select and work only with individual tables.
 *
 *
 * ```php
 * Model::ensureExists($id); // make sure the child table exists and recreate merge table to include everything
 *
 * $data = Model::find()->all(); // retrieves all rows from the parent merge table
 *
 * Model::setTableName(Model::tableNameMerge($id));
 * $data = Model::find()->all(); // retrieves all objects from child table as identified by $id
 * ```
 */
trait MergeTableTrait
{
    /**
     * @var string current table name
     */
    protected static $table;

    /**
     * Returns table name mask to retrieve all the matching table names from database for union
     * @return string the mask string of the table
     */
    public static function tableNameMergeMask()
    {
        return self::tableName().'_';
    }

    /**
     * Returns table name for this specific part.
     * Use this method to specify the from() part of a query, or to set the models tablename @see setTableName() to overwrite default table name
     * @param string|array $mergeId the unique identifier of the part table
     * @return string the table name
     */
    public static function tableNameMerge($mergeId = null)
    {
        // one merge table to be used of the name table_$mergeId[0] (first) if one element array passed
        if ( is_array($mergeId) && count($mergeId) === 1 && !is_array(current($mergeId)) ) {
            $id = current($mergeId);
        }
        // create temporary table from model and change to merge and union of tables as specified in $mergeId and return its name
        elseif ( is_array($mergeId) && count($mergeId) > 1 ) {
            $tableNames = [];
            foreach ( $mergeId as $id ) {
                $tableNames[] = self::tableNameMerge($id);
            }

            return self::createTemporaryMergeTable($tableNames);
        }
        // if not an array use the merge id to get table name table_$mergeId
        elseif ( !is_array($mergeId) && $mergeId ) {
            $id = $mergeId;
        }
        // cant use any of the above for some reason and use the whole merge table unifying all the data
        else {
            return self::tableName();
        }

        return self::tableNameMergeMask().$id;
    }

    /**
     * Create temporary merge table for this request
     * @param array $tableNames the names of the tables that this temp table should merge
     * @return string temporary table name
     */
    public static function createTemporaryMergeTable(Array $tableNames)
    {
        // generate unique temp name
        $tempTableName = sprintf('%s_%s_%s',self::defaultTableName(),getmypid(),date('Ymdhis'));

        foreach ( $tableNames as $part ) {
            if ( !Yii::$app->db->getSchema()->getTableSchema($part) ) {
                throw new Exception('Trying to pull data from non-existent table ' . $part);
            }
        }

        Yii::trace(sprintf('Creating temporary merge %s table from: %s',$tempTableName,var_export($tableNames,true)),__METHOD__);

        $commands = [
            sprintf("CREATE TEMPORARY TABLE {{%s}} LIKE {{%s}}",$tempTableName,self::tableNameModel()),
            sprintf("ALTER TABLE {{%s}} ENGINE=MERGE", $tempTableName),
            sprintf("ALTER TABLE {{%s}} UNION=(%s) INSERT_METHOD=NO",$tempTableName, '{{'.implode('}},{{',$tableNames).'}}'),
        ];

        $transaction = Yii::$app->db->beginTransaction();

        try {
            foreach ( $commands as $command ) {
                Yii::$app->db->createCommand($command)->execute();
            }
        }
        catch (Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $tempTableName;
    }

    /**
     * Returns the model table name, based on which we create all the indetical merge tables
     * @return string model table name
     */
    public static function tableNameModel()
    {
        return self::tableName().'_model';
    }

    /**
     * Determines if the given table name is one of the tables supposed to go into the merge table union
     * @param string $table the table name
     * @return boolean whether the table is child merge table
     */
    public static function isMergeTable($table)
    {
        return strpos($table,self::tableNameMergeMask()) === 0
            && $table !== self::tableName()
            && $table !== self::tableNameModel();
    }

    /**
     * Make sure the merge table exists, create if doesnt and rehas the merge table to include this one
     * @param string $mergeId the unique identifier of the part table
     * @param bool $forceRecreate whether to force recreate even if the table already exists
     * @return string the child table name
     */
    public static function ensureExists($mergeId, $forceRecreate = true)
    {
        //determine name
        $tableName = self::tableNameMerge($mergeId);

        // create part table
        $exists = Yii::$app->db->getSchema()->getTableSchema($tableName);

        if ( !$exists ) {
            $sql = sprintf("
                CREATE TABLE IF NOT EXISTS `%s` LIKE `%s`
                ", $tableName, self::tableNameModel());

            Yii::$app->db->createCommand($sql)->execute();

            self::recreateMergeTable();
        }
        else {
            if ( $forceRecreate ) {
                self::recreateMergeTable();
            }
        }

        return $tableName;
    }

    /**
     * Recreate the merge table by drop, create based on model and then alter to
     * include all the individual child merge tables
     * @return string the merge table name
     */
    public static function recreateMergeTable()
    {
        $modelTable = self::tableNameModel();
        $mergeTable = self::tableNameMerge();

        // load all merge tables except model and the actual merge
        $union = self::getMergeTables();

        //create merge table
        $commands[] = sprintf("DROP TABLE IF EXISTS {{%s}}", $mergeTable);
        $commands[] = sprintf("CREATE TABLE {{%s}} LIKE {{%s}}", $mergeTable, $modelTable);
        $commands[] = sprintf("ALTER TABLE {{%s}} ENGINE=MERGE", $mergeTable);
        // creating read only merge table
        $commands[] = sprintf("ALTER TABLE {{%s}} UNION=(%s) INSERT_METHOD=NO",$mergeTable, '{{'.implode('}},{{',$union).'}}');

        $transaction = Yii::$app->db->beginTransaction();

        try {
            foreach ( $commands as $command ) {
                Yii::$app->db->createCommand($command)->execute();
            }
        }
        catch (Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $mergeTable;
    }

    /**
     * get names of all the merge tables for this model
     * @return array table names
     */
    public static function getMergeTables()
    {
        $schema = Yii::$app->db->getSchema();

        // load all merge tables except model and the actual merge
        $tables = $schema->tableNames;
        $mergeTables = [];

        foreach ($tables as $table) {
            if ( self::isMergeTable($table) ) {
                $mergeTables[] = $table;
            }
        }

        return $mergeTables;
    }

    /**
     * Return the base table name for this model, if another one has been set, return that one, otherwise return default one
     * @return string currently set table name or default if none is set
     */
    public static function tableName()
    {
        if ( !static::$table ) {
            return self::defaultTableName();
        }

        return static::$table;
    }

    /**
     * Set child table name for the model to work with in the current request
     * @param string $table the table name as returned by {@link self::tableName}
     * @return void
     */
    public static function setTableName($table)
    {
        static::$table = $table;
    }

    /**
     * Get the default table name, substitutes the tableName function if not a merge table model
     * @return string default table name
     */
    public static function defaultTableName()
    {
        throw new Exception('The default table name has to be defined.');
    }
}