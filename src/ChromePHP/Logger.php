<?php
namespace ChromePHP;

use \Exception;
use \ReflectionClass;
use \ReflectionProperty;
use \ReflectionException;

/**
 * Refactored and production tested ChromePHP logger
 *
 * Copyright 2012 Craig Campbell
 * Copyright 2013 Aleksey Korzun
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
class ChromePHP
{
    /**
     * Header identifier
     *
     * @var string
     */
    const HEADER = 'X-ChromePHP-Data';

    /**
     * Version identifier
     *
     * @var float
     */
    const VERSION = 3.0;

    /**
     * Backtrace level
     *
     * @var int
     */
    const BACKTRACE_LEVEL = 2;

    /**
     * Type of regular output
     *
     * @var string
     */
    const LOG = 'log';

    /**
     * Type for warning output
     *
     * @var string
     */
    const WARN = 'warn';

    /**
     * Type for error output
     *
     * @var string
     */
    const ERROR = 'error';

    /**
     * Type for information output
     *
     * @var string
     */
    const INFO = 'info';

    /**
     * JSON Schema
     *
     * @var string[]
     */
    protected $json = array(
        'version' => self::VERSION,
        'columns' => array(
            'label',
            'log',
            'backtrace',
            'type'
        ),
        'rows' => array()
    );

    /**
     * Backtrace storage
     *
     * @var mixed[]
     */
    protected $backtraces = array();

    /**
     * Prevent recursion when working with objects referring to each other
     *
     * @var mixed[]
     */
    protected $processed = array();

    /**
     * Instance of ChromePHP
     *
     * @var ChromePHP
     */
    protected static $instance;

    /**
     * Get's an instance of this class
     *
     * @return ChromePHP
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->json['request_uri'] = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A';
        }

        return self::$instance;
    }

    /**
     * Magic method to log different types of messages
     *
     * @throws Exception
     *
     * @param $method
     * @param $arguments
     */
    public function __call($method, $arguments)
    {
        // Determine log type based on method
        switch ($method) {
            case 'log':
                $type = self::LOG;
                break;
            case 'info':
                $type = self::INFO;
                break;
            case 'warn':
                $type = self::WARN;
                break;
            case 'error':
                $type = self::ERROR;
                break;
            default:
                throw new Exception('This method is not supported');
        }

        // We only want to handle 2 arguments
        if (count($arguments) == 2) {
            // Move data into variables
            list($label, $value) = array_values($arguments);

            $data = array(
                'label' => $label,
                'value' => $value,
                'type' => $type
            );

            self::instance()->write($data);
        }
    }

    /**
     * Write output to browser
     *
     * @param mixed[] $data
     */
    protected function write(array $data)
    {
        // Move data into variables
        list($label, $value, $type) = array_values($data);

        // Backtrace information
        $debug = debug_backtrace(false);

        if (isset($debug[self::BACKTRACE_LEVEL]['file'])
            && isset($debug[self::BACKTRACE_LEVEL]['line'])
        ) {
            $backtrace = $debug[self::BACKTRACE_LEVEL]['file'] . ' : ' . $debug[self::BACKTRACE_LEVEL]['line'];
        } else {
            $backtrace = 'unknown';
        }

        // If this is logged on the same line for example in a loop, set it to null to save space
        if (in_array($backtrace, $this->backtraces)) {
            $backtrace = null;
        } else {
            $this->backtraces[] = $backtrace;
        }

        $this->json['rows'][] = array(
            $label,
            $this->convert($value),
            $backtrace,
            $type
        );

        header(self::HEADER . ': ' . $this->encode(self::instance()->json));
    }

    /**
     * Converts an object to a better format for logging
     *
     * @param mixed $class
     *
     * @return mixed
     */
    protected function convert($class)
    {
        // If this isn't an object then just return it
        if (!is_object($class)) {
            return $class;
        }

        // Mark this object as processed so we don't convert it twice,
        // also avoid recursion when objects refer to each other
        $this->processed[] = $class;

        // First add the class name
        $array = array(
            '___class_name' => get_class($class)
        );

        // Loop through object vars
        $variables = get_object_vars($class);
        if ($variables) {
            foreach ($variables as $key => $variable) {
                // Same instance as parent object
                if ($class == $variable || in_array($variable, $this->processed, true)) {
                    $variable = 'recursion - parent object [' . get_class($class) . ']';
                }

                $array[$key] = $this->convert($variable);
            }
        }

        // Loop through the properties and add those
        $reflection = new ReflectionClass($class);
        if ($reflection->getProperties()) {
            foreach ($reflection->getProperties() as $property) {
                // If one of these properties was already added above then ignore it
                if ($variables && isset($variables[$property->getName()])) {
                    continue;
                }

                $property->setAccessible(true);
                $value = $property->getValue($class);

                // Same instance as parent object
                if ($class === $value || in_array($value, $this->processed, true)) {
                    $value = 'recursion - parent object [' . get_class($value) . ']';
                }

                $type = $this->property_type($property);

                $array[$type] = $this->convert($value);
            }
        }

        return $array;
    }

    /**
     * Takes a reflection property and returns a nicely formatted key of the property name
     *
     * @param ReflectionProperty $property
     *
     * @return string
     */
    protected function property_type(ReflectionProperty $property)
    {
        // Determine visibility of property
        if ($property->isPublic()) {
            $visibility = 'public';
        } elseif ($property->isProtected()) {
            $visibility = 'protected';
        } else {
            $visibility = 'private';
        }

        // Check if property is static
        $static = $property->isStatic() ? ' static' : null;

        return $visibility . $static . ' ' . $property->getName();
    }

    /**
     * Encodes data to be sent along with the request
     *
     * @param mixed[] $data
     *
     * @return string
     */
    protected function encode(array $data)
    {
        return base64_encode(utf8_encode(json_encode($data)));
    }
}

