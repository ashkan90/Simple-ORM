<?php

abstract class AbstractQueries {
    abstract function connection();
    abstract function table($name);
    abstract function select($cols = ["*"]);
    abstract function create(array $fields);
    abstract function from($table);
    abstract function where($field, $on, $secondField);
    abstract function orWhere($field, $on, $secondField);
    abstract function like($what);
    abstract function orderBy($column, $type);
    abstract function groupBy($column);
    abstract function prepare();
}


class Query extends AbstractQueries {

    /**
     * @var Builder
     */
    public $builder;

    public function table($name) // from() un alternatifi
    {
        $this->builder->sqlStructure->table = $name;

        return $this;
    }

    public function select($cols = ["*"])
    {
        $this->builder->sqlStructure->columns = $cols;
        $this->builder->sqlStructure->queryType = 'select';
        return $this;

    }

    public function create(array $fields)
    {
        $keys = array_keys($fields);
        $values = array_values($fields);

        $this->builder->sqlStructure->queryType = 'insert';
        $this->builder->sqlStructure->data = [
            'keys' => $keys,
            'values' => $values
        ];

        return $this;
    }

    public function from($table) // table() ın alternatifi
    {
        $this->builder->sqlStructure->table = $table;
        return $this;
    }

    public function where($field, $symbol, $secondField)
    {
        $this->builder->addBinding($secondField);
        $this->builder->setStateForWhere([
            'on' => "`{$field}` {$symbol} ?",
            'bind' => $secondField,
            'type' => 'and',
        ]);

        return $this;
    }

    public function orWhere($field, $symbol, $secondField)
    {
        $this->builder->addBinding($secondField);
        $this->builder->setStateForWhere([
            'on' => "`{$field}` {$symbol} ?",
            'bind' => $secondField,
            'type' => 'or',
        ]);

        return $this;
    }

    public function like($what)
    {
    }

    public function orderBy($column, $type)
    {
        $this->builder->setState(
            'order by',
            "{$column} {$type}",
            true);
//        $this->builder->sqlStructure->aggregate = [$column => $type];
        return $this;
    }

    public function groupBy($column)
    {
        $this->builder->sqlStructure->aggregate = [$column];
        return $this;
    }

    public function connection()
    {
    }

    public function prepare()
    {
        $this->builder->prepareForQuery();


        try {
            $sth = $this->builder->connection->getPdo()->prepare(
                $this->builder->grammar->getQuery()
            );
            $sth->execute($this->builder->bindings);
            $sth->setFetchMode(PDO::FETCH_OBJ);
//            $sth->setFetchMode(PDO::FETCH_CLASS|PDO::FETCH_INTO, $this);
            $data = $sth->fetchAll();

            return $data;


        } catch (PDOException $e) {
            dd($e->getMessage());
        }


    }

    public function setBuilder(Builder $builder)
    {
        $this->builder = $builder;
        $this->builder->startQuery();
    }
}

class Builder {
    public
        $table = "",
        $columns = ["*"],
        $bindings = [],
        $condition = "",
        $query = "";

    protected $states = [
        "where" => [
            "status" => false,
            "conditions" => [],
        ],
        "order by" => [
            "status" => false,
            "on" => null,
        ],
        "group by" => [
            "status" => false,
            "on" => null,
        ],
        "having by" => [
            "status" => false,
            "on" => null,
        ],
        "join" => false,
    ];

    /**
     * @var Connection
     */
    public $connection;

    public $grammar;

    public $sqlStructure;


    public function __construct(Connection $conn)
    {
        $this->connection = $conn;
        $this->grammar = new MYSQLGrammarProcessor();
    }

    public function prepareForQuery()
    {
        $states = array_filter($this->states, function($k, $v) {
            return $k['status'] == true;
        }, ARRAY_FILTER_USE_BOTH);

        $this->grammar->takeStates($states);
        $this->grammar->readAndResolve($this->sqlStructure);
    }

    public function addBinding($val)
    {
        $this->bindings[] = $val;
    }

    public function startQuery()
    {
        $this->sqlStructure = (object) null;
    }

    public function setTable($table)
    {
        $this->table = $table;
    }

    public function setState($whichState, $on, bool $state, $bind = null, $type = null)
    {
        $this->states[$whichState]['status'] = $state;
        $this->states[$whichState]['on'] = $on;
        $this->states[$whichState]['bind'] = $bind;
        $this->states[$whichState]['type'] = $type;
    }

    public function setStateForWhere($conditions)
    {
        $this->states['where']['status'] = true;
        $this->states['where']['conditions'][] = $conditions;
    }
}

class MYSQLGrammarProcessor {

    protected $states;

    protected $grammatically;

    public function readAndResolve($data)
    {
        if (method_exists($this, "{$data->queryType}Resolver")) {
         $this->{"{$data->queryType}Resolver"}($data);
        }
    }

    protected function selectResolver($realData)
    {
        $data = [
            'TABLE' => $realData->table,
            'COLUMNS' => implode(", ", $realData->columns),
        ];

        $this->grammatically['QUERY'] =
            "SELECT {$data['COLUMNS']} FROM `{$data['TABLE']}` ";

        if (count($this->states ?? []) > 0) {
            foreach ($this->states as $queryKeyword => $state) {
                $kw = strtoupper($queryKeyword);
                $this->grammatically['QUERY'] .= "{$kw} ";
                foreach ($state['conditions'] as $i => $condition) {
                    $logic = $i > 0 ? "{$condition['type']} " : '';
                    $this->grammatically['QUERY'] .=
                        "{$logic}{$condition['on']} ";
                }
            }
        }
    }

    protected function insertResolver($realData)
    {
        $data = [
            'TABLE' => $realData->table,
            'KEYS' => implode(", ", HStr::addChar('`', $realData->data['keys'])),
            'VALUES' => implode(", ", HStr::addChar("'", $realData->data['values'])),
        ];

        $this->grammatically['QUERY'] =
            "INSERT INTO {$data['TABLE']} ({$data['KEYS']}) VALUES ({$data['VALUES']})";

    }

    public function takeStates($states)
    {
        $this->states = $states;
    }

    public function getQuery()
    {
        return $this->grammatically['QUERY'];
    }
}

class Connection {

    protected
        $dsn,
        $uname,
        $upw,
        $pdo;

    /**
     * Connection constructor.
     */
    public function __construct()
    {
        $this->dsn = "mysql:dbname=deneme;host=127.0.0.1";
        $this->uname = "root";
        $this->upw = "";

        try {
            $this->pdo = new \PDO($this->dsn, $this->uname, $this->upw);
            $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

        } catch (\PDOException $e) {
            dd($e->getMessage()) ;
        }
    }

    public function getPdo()
    {
        return $this->pdo;
    }
}

class Registration {
    protected static $container = [];

    public static function singleton($creation)
    {
        self::$container[] = $creation;
        return "oluştum";
    }

    public static function container()
    {
        return self::$container;
    }
}

class PrioritySerializer {
    public static function make(array &$list, array $options)
    {
        asort($list);
        HArr::addToPosKV($list, 0, 0, ['DATA' => $options]);
    }
}

class HArr {
    // key ara ve key in değerlerini çevir.
    public static function search($needle, $haystack)
    {
        $found = null;
        foreach ($haystack as $k => $item) {
            if (is_array($needle)) {
                foreach ($needle as $val) {
                    if ($val == $k) {
                        $found = $item;
                        break;
                    }
                }
            } else {
                if ($needle == $k) {
                    $found = $item;
                    break;
                }
            }
        }

        return $found;
    }

    public static function addToPosition(array &$array, $replacement, int $pos)
    {
        array_splice($array, $pos, 0, $replacement);
    }

    public static function addToPosKV(&$input, $offset, $length, $replacement) {
        $replacement = (array) $replacement;
        $key_indices = array_flip(array_keys($input));
        if (isset($input[$offset]) && is_string($offset)) {
            $offset = $key_indices[$offset];
        }
        if (isset($input[$length]) && is_string($length)) {
            $length = $key_indices[$length] - $offset;
        }

        $input = array_slice($input, 0, $offset, TRUE)
            + $replacement
            + array_slice($input, $offset + $length, NULL, TRUE);
    }
}

class HStr {
    public static function addChar($char, $stringOrArray)
    {
        $addedVer = null;
        if (is_string($stringOrArray)) {
            $addedVer = "{$char}{$stringOrArray}{$char}";
        } else {
            foreach ($stringOrArray as $i => $item) {
                $addedVer[$i] = "{$char}{$item}{$char}";
            }
        }

        return $addedVer;
    }
}

class Application {
    private static $in = null;

    /**
     * @var Registration
     */
    private static $registrar = null;

    public static function takeMe()
    {
        if (self::$in == null) {
            self::$in = new static;
        }

        return self::$in;
    }

    public static function register()
    {
        return self::$registrar = new Registration();
    }

    public static function registeredContainer()
    {
        return self::$registrar->container();
    }
}

function app() {
    return Application::takeMe();
}

function dd(...$args) {
    die(print_r(...$args));
}

$connection = new Connection();
$builder = new Builder($connection);
$builder->startQuery();


$query = new Query();
$query->setBuilder($builder);
//app()->register()->singleton(new Connection());

echo "<pre>";
print_r($query
    ->table('come')
    ->create([
        'id' => '2',
        'name' => 'test',
    ])
    ->prepare());
// id = 1 and name = 'emirhan' or surname = '...'