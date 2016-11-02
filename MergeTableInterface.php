<?php

/**
 * @author Marek Petras <mark@markpetras.eu>
 * @link https://github.com/marekpetras/yii2-merge-table/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @version 1.0.0
 */

namespace marekpetras\mergetable;

/**
 * MergeTableInterface provides the implementation multiple merge tables representing one whole set of data.
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
interface MergeTableInterface
{
    /**
     * Returns table name mask to retrieve all the matching table names from database for union
     * @return string the mask string of the table
     */
    public static function tableNameMergeMask();

    /**
     * Returns table name for this specific part
     * @param string $mergeId the unique identifier of the part table
     * @return string the table name
     */
    public static function tableNameMerge($mergeId = null);

    /**
     * Create temporary merge table for this request
     * @param array $tableNames the names of the tables that this temp table should merge
     * @return string temporary table name
     */
    public static function createTemporaryMergeTable(Array $tableNames);

    /**
     * Returns the model table name, based on which we create all the indetical merge tables
     * @return string model table name
     */
    public static function tableNameModel();

    /**
     * Determines if the given table name is one of the tables supposed to go into the merge table union
     * @param string $table the table name
     * @return boolean whether the table is child merge table
     */
    public static function isMergeTable($table);

    /**
     * Make sure the merge table exists, create if doesnt and rehas the merge table to include this one
     * @param string $mergeId the unique identifier of the part table
     * @return string the child table name
     */
    public static function ensureExists($table);

    /**
     * Recreate the merge table by drop, create based on model and then alter to
     * include all the individual child merge tables
     * @return string the merge table name
     */
    public static function recreateMergeTable();

    /**
     * Return the base table name for this model, if another one has been set, return that one, otherwise return default one
     * @return string currently set table name or default if none is set
     */
    public static function tableName();

    /**
     * Set child table name for the model to work with in the current request
     * @param string $table the table name as returned by {@link self::tableName}
     * @return void
     */
    public static function setTableName($table);

    /**
     * Get the default table name, substitutes the tableName function if not a merge table model
     * @return string default table name
     */
    public static function defaultTableName();
}