<?php 

namespace Qik\Database;

use Qik\Core\APIObject;
use Qik\Utility\Utility;
use Qik\Database\{DBManager, DBConnection, DBObjectIterator};
use Qik\Exceptions\Internal\{DBObjectPrimaryKeyNotFound, DbObjectColumnNotFound, DBObjectInsertError};

class DBObject implements APIObject, \IteratorAggregate
{
	private static $columns = [];
	private static $connection;
	private $fields = [];

	protected $table;
	protected $primaryKeyColumn;
	protected $primaryKeyValue;
	protected $prefix;

	public function __construct($pk = null, DBConnection $connection = null)
	{
		if (!empty($connection))
			self::$connection = $connection;

		if (empty($this->prefix))
			$this->prefix = DBManager::GetDefaultTablePrefix();
		
		if (empty($this->table))
			$this->DetermineTable();

		if (!is_array($pk) && !is_object($pk))
			$this->primaryKeyValue = $pk;

		if (!empty($pk))
			$this->Get($pk);
	}

	public function getIterator() 
	{
		return new DBObjectIterator($this->fields);
	}

	public function __set($key, $val)
	{
		$this->fields[$key] = $val;
	}

	public function __get($key)
	{
		return $this->fields[$key];
	}

	public function SetFields($fields)
	{
		foreach ($fields as $key=>$val)
		{
			if ($key == $this->primaryKeyColumn)
				$this->primaryKeyValue = $val;
			
			$this->{$key} = $val;
		}

		return true;
	}

	public function DeterminePrimaryKey() : bool
	{
		//$this->Query('SELECT * FROM '.$this->table);
		$sql = 'SHOW KEYS FROM '.$this->table.' WHERE Key_name = \'primary\'';
		$columns = $this->Query($sql)->FetchAll();
		foreach ($columns as $col)
		{
			if (strtolower($col['Key_name']) == 'primary')
			{
				$this->primaryKeyColumn = $col['Column_name'];
				$this->{$this->primaryKeyColumn} = $this->primaryKeyValue;
				return true;
			}
		}

		throw new DBObjectPrimaryKeyNotFound('DB Primary key not found for '.$this->table.' :: '.$sql);
		
		return false;
	}

	public function DetermineTable()
	{
		$this->table = strtolower($this->prefix.Utility::GetBaseClassNameFromNamespace($this));
	}

	public function GetTable() : string
	{
		if (empty($this->table))
			$this->DetermineTable();
		
		return $this->table;
	}

	public function LoadColumns($refresh = false) : array
	{
		$sql = 'SHOW FULL COLUMNS FROM '.$this->GetTable();
		$columns = $this->Query($sql)->FetchAll(\PDO::FETCH_ASSOC);

		if (!isset(self::$columns[$this->GetTable()]))
			self::$columns[$this->GetTable()] = [];
		elseif (!$refresh)
			return self::$columns[$this->GetTable()];

		foreach ($columns as $column)
		{
			$attributes = explode('||', $column['Comment']);
			$column['Attributes'] = array();

			foreach ($attributes as $attribute)
			{
				if (empty($attribute))
					continue;

				$parts = explode('=', $attribute);
				$column['Attributes'][$parts[0]] = $parts[1];
			}

			self::$columns[$this->GetTable()][$column['Field']] = $column;
		}
			
		return self::$columns[$this->GetTable()];
	}

	public function GetColumns(string $table = null, bool $load = true) : array
	{
		if (empty($table))
			$table = $this->GetTable();

		if (isset(self::$columns[$table]))
			return self::$columns[$table];

		if ($load)
		{
			$this->LoadColumns();
			return $this->GetColumns($table, false);
		}

		return array();
	}

	public function GetForeignKeys(string $table = null)
	{
		if (empty($table))
			$table = $this->table;

		$sql = 'use INFORMATION_SCHEMA;';
		$this->Query($sql);

		$sql = 'SELECT 
					TABLE_NAME,
					COLUMN_NAME,
					CONSTRAINT_NAME,
					REFERENCED_TABLE_NAME,
					REFERENCED_COLUMN_NAME 
				FROM 
					KEY_COLUMN_USAGE
				WHERE 
					TABLE_SCHEMA = "local" AND 
					TABLE_NAME = "'.$this->table.'" AND 
					REFERENCED_COLUMN_NAME IS NOT NULL';

		$keys = $this->Query($sql)->FetchAll(\PDO::FETCH_ASSOC);

		return $keys;
	}

	public function GetModel() : array
	{
		return $this->GetColumns();
	}

	public function GetPublicModel() : array
	{
		$columns = $this->GetColumns();
		$model = array();

		foreach ($columns as $key=>$column)
		{
			if (isset($column['Attributes']['accessibility']) && $column['Attributes']['accessibility'] == 'public')
				$model[$key] = $column;
		}

		return $model;
	}

	public function SetField(string $column = null, $value = null)
	{
		$this->LoadColumns();

		if (!isset(self::$columns[$this->table][$column]))
			throw new DbObjectColumnNotFound('Could not find column '.$column.' to set value '.$value.' in table '.$this->table);

		return $this->fields[$column] = $value;
	}

	private function RequireConnection()
	{
		if (empty(self::$connection))
			return self::$connection = DBManager::GetConnection();

		return false;
	}

	protected function Query(string $sql) : \PDOStatement
	{
		$this->RequireConnection();

		$statement = self::$connection->Query($sql);
		return $statement;
	}

	public function Export()
	{
		$this->RequireConnection();

		return self::$connection->Export('SELECT * FROM '.$this->table, ucwords(str_replace('_', ' ', $this->table)));
	}

	public function GetRecords()
	{

	}

	public function GetAll() : array
	{
		return $this->Query('SELECT * FROM '.$this->table)->FetchAll(\PDO::FETCH_ASSOC);
	}

	public function IsFieldUnique(string $field, $value)
	{
		return !DBQuery::Build()->from($this->GetTable())->where($field.' = ?', $value)->Fetch();
	}

	public function Insert() : bool
	{
		$this->RequireConnection();

		$this->LoadColumns();

		$columns = array_keys($this->fields);

		$keys = [];
		foreach ($columns as $i=>$key)
			array_push($keys, ':'.$key);

		$values = [];
		foreach ($this->fields as $key=>$value)
			$values[':'.$key] = $value;

		$statement = self::$connection->Prepare('INSERT INTO '.$this->table.' ('.implode(',', $columns).') VALUES ('.implode(',', $keys).')');
		
		if (!$statement->Execute($values))
		{
			$errorCode = $statement->errorCode();
			$errorInfo = $statement->errorInfo();
			throw new DBObjectInsertError('DBObject insertion error '.$errorInfo[0].' '.$errorInfo[1].': '.$errorInfo[2]);
		}

		return true;
	}

	public function Get($pk)
	{
		$this->DeterminePrimaryKey();
		$class = get_class($this);
		
		$this->SetFields(DBQuery::Build()->from($this->GetTable())->where($this->primaryKeyColumn.' = ?', $pk)->Fetch());

		return $this;

	}
}