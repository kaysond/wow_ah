<?php
/**
 * object_from_array.class.php only contains the helper class object_from_array
 * 
 * Copyright (C) 2016 Aram Akhavan <kaysond@hotmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package wow_ah
 * @author  Aram Akhavan <kaysond@hotmail.com>
 * @link    https://github.com/kaysond/wow_ah
 * @copyright 2016 Aram Akhavan
 */

/**
 * A helper class to create objects with type-checked and name-checked properties from associative arrays.
 *
 * A constructor receives the input associative array, and checks that each of the class properties exist as a key in the array, and that the values
 * match the property values in type. It's probably not efficient, but it simplifies error-checking and makes code more readable 
 * by having the object documentation implicit in the class definition.
 * 
 * The property $optional specifies property names that are not required.
 * 
 * The property $object specifies property names (array keys) that are object_from_array's, and their class names (array values).
 * 
 * Limitations: can't handle multi-dimensional arrays, or arrays of objects.
 *
 * Example usage:
 * ```php
 * class foo extends object_from_array {
 * 		protected static $optional = array("foo1");
 * 		protected static $objects = array("foo_object" => "bar");
 * 		
 * 		public $foo1 = 0;
 * 		public $foo2 = "";
 * 		public $foo3 = array(0,0,"");
 * 		public $foo_object;
 *
 * 		protected function error($message) {
 * 			throw new Exception($message);
 * 		}
 * }
 *
 * class bar extends object_from_array {
 * 		protected static $optional = array("bar2");
 *
 * 		public $bar1 = 0;
 * 		public $bar2 = "";
 *
 * 		protected function error($message) {
 * 			throw new Exception($message);
 * 		}
 * }
 *
 * $foo = new foo(array("foo2" => "some string",
 * 						"foo3" => array(1,3,"another string"),
 * 						"foo_object" => array("bar1" => 5, "bar2" => "yet another string")
 * 				  ));
 * ```
 */
abstract class object_from_array {
	/** @var array An indexed array containing the names of variables which are not required for the object's construction */
	protected static $optional = array();
	/** @var array An associative array whose keys match property names that are other object_from_array's, and whose values are their respective class names */
	protected static $objects = array();

/**
 * Creates the object by iterating through every property, searching for an element with a key
 * corresponding to the property name, and whose value's type matches the type of the property.
 * 
 * @param Array $assoc_array An input array whose keys match the object properties.
 */
    function __construct($assoc_array) {
        $classname = get_class($this);
        foreach (get_class_vars($classname) as $name => $value) {
        	if ($name != "optional" && $name != "objects") {
	            if (!isset($assoc_array[$name])) {
	            	if (!in_array($name, static::$optional))
	                	$this->error("Could not create " . $classname . " due to missing parameter: $name.");
	            }
	            else
	            	$this->load_property($name, $assoc_array[$name]);
            }
        }
    }

/**
 * Error checks the $input against the class property called $name.
 * 
 * @param  String $name  The property name.
 * @param  Mixed $input  The input to be checked and loaded.
 */
    private function load_property($name, $input) {
    	if (isset(static::$objects[$name])) {
    		$class_name = static::$objects[$name];
    		if (class_exists($class_name)) {
	    		$object = new $class_name($input);
	    		$this->$name = $object;
	    	}
	    	else {
	    		$this->error("Could not create" . get_class($this) . ". Class $class_name does not exist.");
	    	}
    	}
    	elseif (gettype($this->$name) == "array") {
    		foreach ($this->$name as $key => $value) {
		    	if (isset($input[$key])) {
		    		if (gettype($this->$name[$key]) == "array") {
		    			$this->error("Could not create " . get_class($this) . " due to array $name containing an array (multi-dimensional arrays are not yet supported).");
		    		}
		    		elseif (gettype($this->$name[$key]) == gettype($input[$key])) {
		    			$this->$name[$key] = $input[$key];
		    		}
		    		else {
		    			$this->error("Could not create " . get_class($this) . " due to type mismatch of element $key in parameter $name. Expected " . gettype($this->$name[$key]) . " received " . gettype($input[$key]) . ".");
		    		}
		    	}
		    	else {
		    		$this->error("Could not create " . get_class($this) . " due to missing element $key in array $name.");
		    	}

	    	}
    	}
    	else {
	    	if (gettype($input) != gettype($this->$name))
            	$this->error("Could not create " . get_class($this) . " due to type mismatch of parameter: $name. Expected " . gettype($this->$name) . " received " . gettype($input) . ".");
            else
				$this->$name = $input;
    	}
    }
/**
 * Error handling function that is passed an error message.
 * 
 * @param  String $message The error message generated by the constructor.
 */
    abstract protected function error($message);
}

?>