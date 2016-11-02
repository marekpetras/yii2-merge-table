# yii2-merge-table

Yii2 Merge Table
========================

About
-----

This trait allows you to split up a large dataset into multiple, more manageable smaller datasets (MyISAM) using MySQL and the [MERGE engine](http://dev.mysql.com/doc/refman/5.7/en/merge-storage-engine.html)

The idea is to have create a model table which is always empty and the trait then manages all other datasets.

We had the issue of having loads of data that we usually needed to access only by parts, and only very rarely aggregated.

So lets say you have a lots of accounts in the database and you need to give access to your users only to their own accounts but you also need to give overall access to the manager/admin.

If you have milions and milions of rows in the database but only access bits, you always have a few options how to scale your data, you can either replicate, partiotionate, use primary keys/indexes etc.

Another option is to use more identical tables and query only those actually required. If you have a common denominator that you can use (account id, customer id, date ranges etc) and by which you can select appropriate tables its really easy to manage.

You end up with a few tables based on your denominators e.g.:

report              - this is the merge table
report_15432        - these are the MyIsam tables with actual data
report_15435
report_12344
...
report_12312
report_model        - this is the model table which is used to create the partial tables - this is the only one you actually have to create in your database yourself




```

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist marekpetras/yii2-merge-table "^1.0"
```

or add

```
"marekpetras/yii2-merge-table": "^1.0"
```

to the require section of your `composer.json` file.

Usage
-----

Create the model class with which you will work pretty much the same way as with any other model class, the only thing you need to add is the defaultTableName() static function, whatever you specify here, the merge table will be called this in your database, the trait will create it by itself.

```php
<?php

use yii\db\ActiveRecord;
use marekpetras\mergetable\MergeTableTrait;
use marekpetras\mergetable\MergeTableInterface;

class Report extends ActiveRecord implements MergeTableInterface
{
    use MergeTableTrait;

    /**
     * Get the default table name, substitutes the tableName function if not a merge table model
     * @return string default table name
     */
    public static function defaultTableName()
    {
        return 'report';
    }
}

?>
```
You need to come up with some sort of logical way to split your data. It could be account id, client id, yearmonth, or anything that will allow you to work with partial data if you need to.
I usually just load the data (lot of it - milions of rows) from file.

Before you start to insert data, make sure the desired table exists, and if not the trait will create it.

```php
<?php

$id = 15432;
$tableName = Report::ensureExists($id);

$sql = sprintf("
    LOAD DATA INFILE '%s'
    REPLACE
    INTO TABLE `%s`
    FIELDS TERMINATED BY ',' ENCLOSED BY '\"'
    LINES TERMINATED BY '\n'
    ", $file, $tableName);

$rows = Yii::$app->db->createCommand($sql)->execute();
?>
```

Then if you want to access this data, you have to set which table you want to access (if you do not specify anything, the merge table will be queried)

```php
<?php

$id = 15432;
Report::setTableName(Report::tableNameMerge($id));

$query = Report::find();
/*
    SELECT * FROM report_15432;
*/

```

There are a few ways to access the data, for example you might want to aggregate data from a few of those. Just send an array of the ids to the model first.
The trait will create a temporary merge table which you can then query. It will be dropped at the

```php
<?php

$ids = [15432,12344];
Report::setTableName(Report::tableNameMerge($id));

$query = Report::find();
/*
    CREATE TEMPORARY TABLE unique_temp_name LIKE report_model;
    ALTER TABLE unqiue_temp_name ENGINE=MERGE, UNION=(report_15432,report_12344) INSERT_METHOD=NO;

    SELECT * FROM unqiue_temp_name;
*/

```

You will only ever query the whole merge table if you choose to do so, and if you do set a table name.
```php
<?php

$query = Report::find();
/*
    SELECT * FROM report;
*/

```

You can use the query as any other, in your data providers etc.