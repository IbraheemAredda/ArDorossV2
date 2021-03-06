<?php

abstract class Database implements ArrayAccess
{
    public $name;
    public $connection;
    public $tables;

    public function __construct($name, $connection)
    {
        $this->name = $name;
        $this->connection = $connection;
        
        $this->setup();
    }

    // Get actual name
    public function get_name()
    {
        return empty($this->name) || !isset($this->name) ? get_class($this) : $this->name;
    }

    // Contains the setup instructions
    protected abstract function setup();
    
    // Saves all made changes
    public function save()
    {
        // Foreach table
        foreach ($this->tables as $table)
        {
            // Insert what's in the insert queue
            foreach ($table->getInsertQueue() as $instance)
            {
                $query = SQLConverter::get_insert($instance);

                if (!SQLConverter::execute_command($query, $this->connection))
                    throw new Exception ("InsertionException: " . $this->connection->error);
            }

            // Delete 
            foreach ($table->getDeleteQueue() as $instance)
            {
                $query = SQLConverter::get_delete($instance);

                if (!SQLConverter::execute_command($query, $this->connection))
                    throw new Exception ("Error happened while attempting to delete");
            }

            // Update
            foreach ($table->getUpdateQueue() as $instance)
            {
                $query = SQLConverter::get_update($instance);

                if (!SQLConverter::execute_command($query, $this->connection))
                    throw new Exception ("Error happened while attempting to delete");
            }
        }
    }

    // Check if the database is created
    public function is_created()
    {
        return $this->connection->select_db($this->get_name());
    }

    // Create database if not existed
    public function create()
    {
        if (is_null($this->tables))
            throw new Exception("This database has no tables to create.");

        $indicator = $this->connection->multi_query($this->get_creation_script());

        if (!$indicator)
            throw new Exception(mysqli_error($this->connection));

        return $indicator;
    }

    // Maps tables
    public function map()
    {
        foreach(func_get_args() as $tableName)
            $this->tables[$tableName] = new Table($tableName);
    }

    // Loading data from all tables
    public function refresh ($lazyMode = false)
    {
        // Save changes before refreshing
        $this->save();

        // Load tables
        foreach($this->tables as $name => $table)
            $this->tables[$name] = $this->load_table($name);

        // Load children containers
        if (!$lazyMode)
        foreach ($this->tables as $table)
            $this->load_children ($table);
    }

    // Loading children containers
    public function load_children (Table $table)
    {
        $tableName = $table->class;

        $containers = SQLConverter::get_children_containers($tableName);

        if (count($containers) == 0)
            return;

        $tableKey = SQLConverter::get_primary_property($tableName);

        foreach ($containers as $container)
        {
            $childTableName = SQLConverter::get_constraint($container, "@hasMany");

            if (!$this->offsetExists($childTableName))
                continue;
            
            $childTable = $this->tables[$childTableName];

            // Get the property that references the parent in the child's table
            $childTableReferencer = SQLConverter::search_property($childTableName, "@references", $tableName);
            
            if ($childTableReferencer == null)
                continue;

            foreach ($table as $record)
            {
                // Retrieve referencing records
                $virtualContainer = new Table($childTable->class);

                // Record id
                $identifier = $tableKey->getValue ($record);

                foreach ($childTable as $childRecord)
                {
                    // The property that contains the reference of the parent
                    $reference = $childTableReferencer->getValue ($childRecord);

                    if ($reference == null)
                        continue;

                    // Get the child's referencer's id                     
                    $referenceIdentifier = $tableKey->getValue ($reference);

                    if (strcmp($identifier, $referenceIdentifier) == 0)
                        $virtualContainer->add($childRecord);
                }

                // Finalize
                $container->setValue ($record, $virtualContainer);
            }
        }
    }

    // Constructing the creation script
    public function get_creation_script()
    {
        $script = "CREATE DATABASE IF NOT EXISTS " . $this->get_name() . "; ";
        $script .= "USE " . $this->get_name() . "; ";

        foreach ($this->tables as $key => $table)
            $script .= SQLConverter::create_table($key) . "; ";

        return $script;
    }

    // Responsible for loading one table
    public function load_table($className)
    {
        // Reflection helper
        $reflection = new ReflectionClass($className);
        // Create a container
        $table = new Table($className);
        // Bring data
        $data = SQLConverter::select_query("SELECT * FROM $className", $this->connection);

        // Fill base columns
        foreach ($data as $record)
        {
            // Create an instance
            $instance = $reflection->newInstance();
            /** 
             * Foreach column in the record, find the corresponding property
             * and affect it to the property
            */
            foreach ($record as $column => $value)
            {
                // Get property
                $property = $reflection->hasProperty($column) 
                    ? $reflection->getProperty($column) 
                    : SQLConverter::search_property($className, "@name", $column);

                if ($property == null) continue;
                
                // Check if the property is responsible for loading referers records
                $referers = SQLConverter::get_constraint($property, "@hasMany");
                
                if ($referers != null)
                    continue;

                // Check if the property is a referencing column
                $reference = SQLConverter::get_constraint($property, "@references");

                // If it is, this property shall contain the instance of the referenced class
                $property->setValue ($instance, ($reference != null) ? $this->tables[$reference]->find($value) : $value);
            }
            // Add the instance to the table
            $table->add($instance);
        }

        // Clear queues
        $table->clearQueues();

        return $table;
    }

    // Defining ArrayAccess methods
    function offsetExists($offset)
    {
        return isset($this->tables[$offset]);
    }
    function offsetSet($offset, $value)
    {
        $this->tables[$offset] = $value;
    }
    function offsetGet($offset)
    {
        if (!$this->offsetExists($offset))
            throw new Exception("'$offset' can't be found in the mapped tables");

        return $this->tables[$offset];
    }
    function offsetUnset($offset)
    {
        if ($this->offsetExists($offset))
            unset($this->tables[$offset]);
    }
}