<?php
/**
 * CAUTION! This class is a prototype, security model is inadequate and it's not unit-tested!
 *
 * A prototype implementation of an API-to-ORM mapping.
 *
 * Don't forget to include the security token or ID from silverstripe-webservices module (see webservices/Readme.md).
 *
 * Querying with GET requests:
 * /contentapi/Members/all - list all members
 * /contentapi/Members/all?filter=FirstName:Mateusz - list all members with FirstName=Mateusz
 * /contentapi/Members/all?filter=FirstName:Mateusz,Surname:Uzdowski - list members that satisfy both conditions (AND)
 * /contentapi/Members/all?filter=FirstName:StartsWith:Mat - use a StartsWith filter on FirstName
 * /contentapi/Members/all?filter=FirstName:Mateusz|Mark - list members with FirstName Mark or Mateusz (OR)
 * /contentapi/Members/all?filter=FirstName:Mateusz|Mark,Surname:Uzdowski - (Mateusz OR Mark) AND Uzdowski
 * /contentapi/Members/all?exclude=FirstName:Mateusz - list all members whose FirstName is not Mateusz
 * /contentapi/Members/all?sort=+LastName,-FirstName - sort members by LastName ASC, FirstName DESC
 * /contentapi/Members/all?sort=__RAND - sort members randomly
 * /contentapi/Members/all?limit=3 - get just three first members
 * /contentapi/Members/all?limit=3,10 - get just three first members starting at 10
 * /contentapi/Members/first - get the first member
 * /contentapi/Members/last - get the last member
 * /contentapi/Members/column/FirstName - get the array of all FirstNames
 * This one Needs an implementation for SS_Map to JSON converter, then it will work fine:
 * /contentapi/Members/map/ID,FirstName - map IDs to FirstNames
 *
 * You can combine filter, exclude, sort and limit and combine it with any method:
 * /contentapi/Members/column/ID?filter=FirstName:Mateusz&sort=+FirstName&exclude=FirstName:Mark&limit=3,10
 *
 * Creating records via POST payload:
 * /contentapi/Members/
 *
 * Updating records via POST payload:
 * /contentapi/Members/1
 *
 * Deleting records via POST:
 * /contentapi/Members/delete/1
 *
 */

class ContentAPIController extends WebServiceController
{

    protected $format = 'json';

    public function handleService()
    {
        $model = $this->request->param('Model');
        // Arg1 can be a method or an ID (as in contentapi/Member/last or contentapi/Member/1)
        $arg1 = $this->request->param('Arg1');
        // Arg2 can be an ID or other parameter (as in contentapi/Member/column/ID or contentapi/Member/delete/1)
        $arg2 = $this->request->param('Arg2');

        $body = $this->request->getBody();
        $requestType = strlen($body) > 0 ? 'POST' : $this->request->httpMethod();
        $queryParams = $this->getRequestArgs($requestType);

        // Validate Model name + store
        if ($model) {
            if (!class_exists($model)) {
                return new WebServiceException(500, "Model '$model' does not exist");
            }
        } else {
            // If model missing, stop + return blank object
            return new WebServiceException(500, "ContentAPIController::handleService expects \$Model parameter");
        }

        // Map HTTP word to module method
        $response = '';
        switch ($requestType) {
        case 'GET';
            $response = $this->findModel($model, $arg1, $arg2, $queryParams);
            break;

        case 'POST':
            if ($arg1=='delete') {
                $response = $this->deleteModel($model, $arg1, $arg2);
            } else {
                if ($arg1) {
                    // URL parameter overrides the ID in the body.
                    $response = $this->updateModel($model, $arg1, $arg2, $queryParams);
                } elseif (isset($queryParams['ID'])) {
                    $response = $this->updateModel($model, $queryParams['ID'], $arg2, $queryParams);
                } else {
                    $response = $this->createModel($model, $queryParams);
                }
            }
            break;

        default:
            return new WebServiceException(500, "Invalid HTTP verb");
            break;
        }

        $responseItem = $this->convertResponse($response);
        return $this->converters[$this->format]['FinalConverter']->convert($responseItem);
    }

    public function findModel($model, $arg1, $arg2 = null, $queryParams = null)
    {
        if (is_numeric($arg1)) {
            return DataObject::get($model)->byID($arg1);
        } elseif (in_array($arg1, array('all', 'count', 'last', 'first', 'map', 'column'))) {
            $data = DataList::create($model);

            foreach ($queryParams as $param => $value) {
                switch ($param) {

                case 'filter':
                case 'exclude':
                    // For now only support a comma separated list of field conditions that will be ANDed.
                    // The values can be a "|" separated list that will be ORed.
                    $fields = explode(',', $value);
                    $fieldFilters = array();

                    foreach ($fields as $field) {
                        $lastColonPos = strrpos($field, ':');
                        $columnAndFilters = substr($field, 0, $lastColonPos);
                        $conditions = substr($field, $lastColonPos + 1);

                        $conditionArray = explode('|', $conditions);
                        if (count($conditionArray)==1) {
                            $fieldFilters["$columnAndFilters"] = $conditionArray[0];
                        } else {
                            $fieldFilters["$columnAndFilters"] = $conditionArray;
                        }
                    }

                    if ($param=='filter') {
                        $data = $data->filter($fieldFilters);
                    } else {
                        $data = $data->exclude($fieldFilters);
                    }
                    break;

                case 'sort':
                    if ($value=='__RAND') {
                        $data = $data->sort('RAND()');
                    } else {
                        $fields = explode(',', $value);
                        $fieldSorts = array();
                        foreach ($fields as $field) {
                            if (preg_match('/([+-]?)([A-Za-z]+)/', $field, $matches)) {
                                if (count($matches)==3) {
                                    $direction = $matches[1]=='-' ? 'DESC' : 'ASC';
                                    $column = $matches[2];
                                    $fieldSorts[$column] = $direction;
                                }
                            }
                        }

                        $data = $data->sort($fieldSorts);
                    }
                    break;

                case 'limit':
                    $limits = explode(',', $value);
                    if (count($limits)==2) {
                        $data = $data->limit($limits[0], $limits[1]);
                    } elseif (count($limits)==1) {
                        $data = $data->limit($limits[0]);
                    }
                    break;

                default:
                    // Skip unrecognised query parameters.
                    break;
                }
            }

            // Apply a method that finalises the query.
            switch ($arg1) {
            case 'count':
                return $data->count();

            case 'last':
                return $data->Last();

            case 'first':
                return $data->First();

            case 'map':
                $map = explode(',', $arg2);
                if (count($map)==2) {
                    return $data->map($map[0], $map[1]);
                } else {
                    return null;
                }

            case 'column':
                return $data->column($arg2);

            case 'all':
            default:
                return $data;
            }
        }

        throw new WebServiceException(500,
            'Unrecognised combination of method and parameters in ContentAPIController::findModel');
    }

    public function createModel($model, $queryParams)
    {
        $newModel = $this->injector->create($model);
        $newModel->write();

        return $this->updateModel($model, $newModel->ID, null, $queryParams);
    }

    public function updateModel($model, $id, $arg2, $queryParams)
    {
        $object = DataObject::get($model)->byID($id);

        if (!$object) {
            return new WebServiceException(404, "Record not found.");
        }

        if ($object) {
            $has_one = Config::inst()->get($object->ClassName, 'has_one', Config::INHERITED);
            $has_many = Config::inst()->get($object->ClassName, 'has_many', Config::INHERITED);
            $many_many = Config::inst()->get($object->ClassName, 'many_many', Config::INHERITED);
            $belongs_many_many = Config::inst()->get($object->ClassName, 'belongs_many_many', Config::INHERITED);

            $hasChanges = false;
            $hasRelationChanges = false;

            foreach ($queryParams as $attribute => $value) {
                if (!is_array($value)) {
                    if (is_array($has_one) && array_key_exists($attribute, $has_one)) {
                        $relation = $attribute . 'ID';
                        $object->$relation = $value;
                        $hasChanges = true;
                    } elseif ($object->{$attribute} != $value) {
                        $object->{$attribute} = $value;
                        $hasChanges = true;
                    }
                } else {

                    //has_many, many_many or belong_many_many
                    if (
                        is_array($has_many) && array_key_exists($attribute, $has_many) ||
                        is_array($many_many) && array_key_exists($attribute, $many_many) ||
                        is_array($belongs_many_many) && array_key_exists($attribute, $belongs_many_many)
                    ) {
                        $hasRelationChanges = true;
                        $ssList = $object->{$attribute}();
                        $ssList->removeAll(); //reset list
                        foreach ($value as $id) {
                            $ssList->add($id);
                        }
                    }
                }
            }

            if ($hasChanges || $hasRelationChanges) {
                $object->write(false, false, false, $hasRelationChanges);
            }
        }

        return DataObject::get($model)->byID($object->ID);
    }

    public function deleteModel($model, $arg1, $id)
    {
        if ($id) {
            $object = DataObject::get($model)->byID($id);
            if ($object) {
                $object->delete();
            } else {
                return new WebServiceException(404, "Record not found.");
            }
        } else {
            return new WebServiceException(500, "Invalid or missing ID. Received '$id'.");
        }
    }
}
