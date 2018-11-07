<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use \Illuminate\Support\Facades\Log;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * @var array
     *
     * The $deletes variable is to be set when you create a new object, use it in the tests, and then wish for it to
     * be deleted upon the destruction of the test object. The variables listed here must be present in $this with a
     * visibility of protected or public. It is assumed that these variables are Eloquent models.
     */
    protected $deletes = [];

    /**
     * Delete the Eloquent Models defined in $deletes.
     *
     * @return void
     */
    protected function deleteVariables()
    {
        foreach ($this->deletes as $variableToDelete) {
            $variableToDelete = $this->$variableToDelete;
            if ($variableToDelete instanceof Model) {
                // Get the information about the table we're deleting from so we may reset it to the state it was before
                // the variable was created
                $primaryKey = $variableToDelete->getKeyName();
                $tableName = $variableToDelete->getTable();
                // Delete the variable
                try {
                    $variableToDelete->delete();
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
                // Bring the database back to the original primary key value
                $this::refreshDB([$primaryKey=>$tableName]);
            }
        }
    }

    /**
     * Upon the destruction of a test, delete the models created in it.
     */
    public function tearDown()
    {
        $this->deleteVariables();
    }

    /**
     * Reset the primary key of a given table to the smallest available ID
     *
     * @param $tables | string or array
     *        Acceptable formats: 'table 1', ['table 1', ...], ['primary key 1'=>'table 1', ...]
     *
     * @return void
     */
    public static function refreshDB($tables)
    {
        $tables = is_array($tables) ? $tables : [$tables];
        foreach ($tables as $primary_key => $table) {

            $max = DB::table($table)->max(is_string($primary_key) ? $primary_key : 'id') + 1;

            $dbDriver = env('DB_CONNECTION');
            if ($dbDriver == 'pgsql') {
                $sql = 'ALTER SEQUENCE '.$table.'_'.$primary_key.'_seq RESTART WITH '.$max;
            } elseif ($dbDriver == 'mysql') {
                $sql = "ALTER TABLE $table AUTO_INCREMENT = $max";
            } else {
                Log::warning("You are not using postgres or mysql, the refreshDB function will not work and your tables may get very large.");
                return;
            }

            DB::statement($sql);
        }
    }
}
