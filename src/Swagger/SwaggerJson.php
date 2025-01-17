<?php
namespace Hyperf\Apidog\Swagger;

use Hyperf\Apidog\Annotation\ApiResponse;
use Hyperf\Apidog\Annotation\Body;
use Hyperf\Apidog\Annotation\Param;
use Hyperf\Apidog\ApiAnnotation;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Annotation\Mapping;
use Hyperf\Utils\ApplicationContext;

class SwaggerJson
{

    public $config;

    public $swagger;

    public function __construct()
    {
        $this->config = ApplicationContext::getContainer()
                                          ->get(ConfigInterface::class);
        $this->swagger = $this->config->get('swagger');
    }

    public function addPath($className, $methodName)
    {
        $classAnnotation = ApiAnnotation::classMetadata($className);
        $methodAnnotations = ApiAnnotation::methodMetadata($className, $methodName);
        $params = [];
        $responses = [];
        /** @var \Hyperf\Apidog\Annotation\GetApi $mapping */
        $mapping = null;
        foreach ($methodAnnotations as $option) {
            if ($option instanceof Mapping) {
                $mapping = $option;
            }
            if ($option instanceof Param) {
                $params[] = $option;
            }
            if ($option instanceof ApiResponse) {
                $responses[] = $option;
            }
        }
        $tag = $classAnnotation->tag ?: $className;
        $this->swagger['tags'][$tag] = [
            'name' => $tag,
            'description' => $classAnnotation->description,
        ];
        $base_path = $this->basePath($className);
        $path = $base_path . '/' . $methodName;
        if ($mapping->path) {
            $path = $mapping->path;
        }
        $method = strtolower($mapping->methods[0]);
        $this->swagger['paths'][$path][$method] = [
            'tags' => [
                $tag,
            ],
            'summary' => $mapping->summary,
            'parameters' => $this->makeParameters($params, $path),
            'consumes' => [
                "application/json",
            ],
            'produces' => [
                "application/json",
            ],
            'responses' => $this->makeResponses($responses, $path, $method),
            'description' => $mapping->description,
        ];

    }

    public function basePath($className)
    {
        return controllerNameToPath($className);
    }

    public function initModel()
    {
        $array_schema = [
            'type' => 'array',
            'required' => [],
            'items' => [
                'type' => 'string'
            ],
        ];
        $object_schema = [
            'type' => 'object',
            'required' => [],
            'items' => [
                'type' => 'string'
            ],
        ];

        $this->swagger['definitions']['ModelArray'] = $array_schema;
        $this->swagger['definitions']['ModelObject'] = $object_schema;
    }

    public function rules2schema($rules)
    {
        $schema = [
            'type' => 'object',
            'required' => [],
            'properties' => [],
        ];
        foreach ($rules as $field => $rule) {
            $property = [];
            $field_name_label = explode('|', $field);
            $field_name = $field_name_label[0];
            if (!is_array($rule)) {
                $type = $this->getTypeByRule($rule);
            } else {
                //TODO 结构体多层
                $type = 'string';
            }
            if ($type == 'array') {
                $property['$ref'] = '#/definitions/ModelArray';;
            }
            if ($type == 'object') {
                $property['$ref'] = '#/definitions/ModelObject';;
            }
            $property['type'] = $type;
            $property['description'] = $field_name_label[1] ?? '';
            $schema['properties'][$field_name] = $property;
        }

        return $schema;
    }

    public function getTypeByRule($rule)
    {
        $default = explode('|', preg_replace('/\[.*\]/', '', $rule));
        if (array_intersect($default, ['int', 'lt', 'gt', 'ge'])) {
            return 'integer';
        }
        if (array_intersect($default, ['array'])) {
            return 'array';
        }
        if (array_intersect($default, ['object'])) {
            return 'object';
        }
        return 'string';
    }

    public function makeParameters($params, $path)
    {
        $this->initModel();
        $path = str_replace(['{', '}'], '', $path);
        $parameters = [];
        /** @var \Hyperf\Apidog\Annotation\Query $item */
        foreach ($params as $item) {
            $parameters[$item->name] = [
                'in' => $item->in,
                'name' => $item->name,
                'description' => $item->description,
                'required' => $item->required,
                'type' => $item->type,
            ];
            if ($item instanceof Body) {
                $modelName = implode('', array_map('ucfirst', explode('/', $path)));
                $schema = $this->rules2schema($item->rules);
                $this->swagger['definitions'][$modelName] = $schema;
                $parameters[$item->name]['schema']['$ref'] = '#/definitions/' . $modelName;
            }
        }

        return array_values($parameters);
    }

    public function makeResponses($responses, $path, $method)
    {
        $path = str_replace(['{', '}'], '', $path);
        $resp = [];
        /** @var ApiResponse $item */
        foreach ($responses as $item) {
            $resp[$item->code] = [
                'description' => $item->description,
            ];
            if ($item->schema) {
                $modelName = implode('', array_map('ucfirst', explode('/', $path))) . ucfirst($method) .'Response' . $item->code;
                $ret = $this->responseSchemaTodefinition($item->schema, $modelName);
                if ($ret) {
                    $resp[$item->code]['schema']['$ref'] = '#/definitions/' . $modelName;
                }
            }
        }

        return $resp;
    }

    public function responseSchemaTodefinition($schema, $modelName, $level = 0)
    {
        if (!$schema) {
            return false;
        }
        $definition = [];
        foreach ($schema as $key => $val) {
            $_key = str_replace('_', '', $key);
            $property = [];
            $property['type'] = gettype($val);
            if (is_array($val)) {
                $definition_name = $modelName . ucfirst($_key);
                if ($property['type'] == 'array' && isset($val[0])) {
                    if (is_array($val[0])) {
                        $property['type'] = 'array';
                        $ret = $this->responseSchemaTodefinition($val[0], $definition_name, 1);
                        $property['items']['$ref'] = '#/definitions/' . $definition_name;
                    } else {
                        $property['type'] = 'array';
                        $property['items']['type'] = gettype($val[0]);
                    }
                } else {
                    $property['type'] = 'object';
                    $ret = $this->responseSchemaTodefinition($val, $definition_name, 1);
                    $property['$ref'] = '#/definitions/' . $definition_name;
                }
                if (isset($ret)) {
                    $this->swagger['definitions'][$definition_name] = $ret;
                }
            } else {
                $property['default'] = $val;
            }
            $definition['properties'][$key] = $property;
        }
        if ($level === 0) {
            $this->swagger['definitions'][$modelName] = $definition;
        }

        return $definition;
    }

    public function save()
    {
        $this->swagger['tags'] = array_values($this->swagger['tags'] ?? []);
        $output_file = $this->swagger['output_file'] ?? '';
        if (!$output_file) {
            return;
        }
        unset($this->swagger['output_file']);
        file_put_contents($output_file, json_encode($this->swagger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
