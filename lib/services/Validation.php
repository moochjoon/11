<?php
/**
 * Validation Service
 * Data validation and error handling
 */

namespace App\Services;

class Validation
{
    private $errors = [];
    private $data = [];
    private $rules = [];

    /**
     * Constructor
     */
    public function __construct($data = [])
    {
        $this->data = $data;
    }

    /**
     * Validate data
     */
    public function validate($rules)
    {
        $this->rules = $rules;
        $this->errors = [];

        foreach ($rules as $field => $rule_string) {
            $this->validateField($field, $rule_string);
        }

        return empty($this->errors);
    }

    /**
     * Validate single field
     */
    private function validateField($field, $rule_string)
    {
        $value = $this->data[$field] ?? null;
        $rules = explode('|', $rule_string);

        foreach ($rules as $rule) {
            $rule = trim($rule);

            if (empty($rule)) {
                continue;
            }

            // Parse rule
            if (strpos($rule, ':') !== false) {
                [$rule_name, $rule_param] = explode(':', $rule, 2);
            } else {
                $rule_name = $rule;
                $rule_param = null;
            }

            // Call validation method
            $method = 'validate' . ucfirst($rule_name);

            if (method_exists($this, $method)) {
                $this->$method($field, $value, $rule_param);
            }
        }
    }

    /**
     * Required validation
     */
    private function validateRequired($field, $value, $param = null)
    {
        if (empty($value) && $value !== '0' && $value !== 0 && $value !== false) {
            $this->addError($field, "{$field} is required");
        }
    }

    /**
     * Min length validation
     */
    private function validateMin($field, $value, $param)
    {
        if (!empty($value) && strlen($value) < (int)$param) {
            $this->addError($field, "{$field} must be at least {$param} characters");
        }
    }

    /**
     * Max length validation
     */
    private function validateMax($field, $value, $param)
    {
        if (!empty($value) && strlen($value) > (int)$param) {
            $this->addError($field, "{$field} must not exceed {$param} characters");
        }
    }

    /**
     * Email validation
     */
    private function validateEmail($field, $value, $param = null)
    {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "{$field} must be a valid email address");
        }
    }

    /**
     * URL validation
     */
    private function validateUrl($field, $value, $param = null)
    {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, "{$field} must be a valid URL");
        }
    }

    /**
     * Numeric validation
     */
    private function validateNumeric($field, $value, $param = null)
    {
        if (!empty($value) && !is_numeric($value)) {
            $this->addError($field, "{$field} must be numeric");
        }
    }

    /**
     * Integer validation
     */
    private function validateInteger($field, $value, $param = null)
    {
        if (!empty($value) && !is_int($value) && !ctype_digit((string)$value)) {
            $this->addError($field, "{$field} must be an integer");
        }
    }

    /**
     * Alpha validation (letters only)
     */
    private function validateAlpha($field, $value, $param = null)
    {
        if (!empty($value) && !ctype_alpha($value)) {
            $this->addError($field, "{$field} must contain only letters");
        }
    }

    /**
     * Alphanumeric validation
     */
    private function validateAlphanumeric($field, $value, $param = null)
    {
        if (!empty($value) && !ctype_alnum($value)) {
            $this->addError($field, "{$field} must contain only letters and numbers");
        }
    }

    /**
     * Confirmed validation (field matches another field)
     */
    private function validateConfirmed($field, $value, $param = null)
    {
        $confirm_field = $field . '_confirmation';
        $confirm_value = $this->data[$confirm_field] ?? null;

        if ($value !== $confirm_value) {
            $this->addError($field, "{$field} does not match confirmation");
        }
    }

    /**
     * Unique validation (check if exists in database)
     */
    private function validateUnique($field, $value, $param)
    {
        if (empty($value)) {
            return;
        }

        [$table, $column] = explode(',', $param);
        $db = new \Namak\Database(require BASE_PATH . '/config/database.php');

        $exists = $db->first("SELECT id FROM {$table} WHERE {$column} = ? LIMIT 1", [$value]);

        if ($exists) {
            $this->addError($field, "{$field} already exists");
        }
    }

    /**
     * In validation (must be in list)
     */
    private function validateIn($field, $value, $param)
    {
        if (empty($value)) {
            return;
        }

        $allowed_values = explode(',', $param);

        if (!in_array($value, $allowed_values)) {
            $this->addError($field, "{$field} must be one of: " . implode(', ', $allowed_values));
        }
    }

    /**
     * Not in validation (must not be in list)
     */
    private function validateNotIn($field, $value, $param)
    {
        if (empty($value)) {
            return;
        }

        $forbidden_values = explode(',', $param);

        if (in_array($value, $forbidden_values)) {
            $this->addError($field, "{$field} cannot be: " . implode(', ', $forbidden_values));
        }
    }

    /**
     * Date validation
     */
    private function validateDate($field, $value, $param = null)
    {
        if (empty($value)) {
            return;
        }

        $format = $param ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, $value);

        if (!$date || $date->format($format) !== $value) {
            $this->addError($field, "{$field} must be a valid date ({$format})");
        }
    }

    /**
     * After date validation
     */
    private function validateAfter($field, $value, $param)
    {
        if (empty($value) || empty($this->data[$param])) {
            return;
        }

        $value_time = strtotime($value);
        $param_time = strtotime($this->data[$param]);

        if ($value_time <= $param_time) {
            $this->addError($field, "{$field} must be after {$param}");
        }
    }

    /**
     * Before date validation
     */
    private function validateBefore($field, $value, $param)
    {
        if (empty($value) || empty($this->data[$param])) {
            return;
        }

        $value_time = strtotime($value);
        $param_time = strtotime($this->data[$param]);

        if ($value_time >= $param_time) {
            $this->addError($field, "{$field} must be before {$param}");
        }
    }

    /**
     * Regex validation
     */
    private function validateRegex($field, $value, $param)
    {
        if (empty($value)) {
            return;
        }

        if (!preg_match($param, $value)) {
            $this->addError($field, "{$field} format is invalid");
        }
    }

    /**
     * Phone validation
     */
    private function validatePhone($field, $value, $param = null)
    {
        if (empty($value)) {
            return;
        }

        if (!preg_match('/^\+?[0-9]{10,}$/', $value)) {
            $this->addError($field, "{$field} must be a valid phone number");
        }
    }

    /**
     * IP validation
     */
    private function validateIp($field, $value, $param = null)
    {
        if (empty($value)) {
            return;
        }

        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            $this->addError($field, "{$field} must be a valid IP address");
        }
    }

    /**
     * JSON validation
     */
    private function validateJson($field, $value, $param = null)
    {
        if (empty($value)) {
            return;
        }

        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($field, "{$field} must be valid JSON");
        }
    }

    /**
     * Array validation
     */
    private function validateArray($field, $value, $param = null)
    {
        if (!is_array($value)) {
            $this->addError($field, "{$field} must be an array");
        }
    }

    /**
     * Add error
     */
    private function addError($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Get errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get error for field
     */
    public function getError($field)
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Check if has errors
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Get first error message
     */
    public function getFirstError($field)
    {
        $errors = $this->getError($field);
        return $errors[0] ?? null;
    }

    /**
     * Get all error messages as string
     */
    public function getErrorsAsString($field = null)
    {
        if ($field) {
            $errors = $this->getError($field);
            return $errors ? implode(', ', $errors) : '';
        }

        $all_errors = [];
        foreach ($this->errors as $field_errors) {
            $all_errors = array_merge($all_errors, $field_errors);
        }
        return implode(', ', $all_errors);
    }

    /**
     * Static validation method
     */
    public static function make($data, $rules)
    {
        $validator = new self($data);
        $validator->validate($rules);
        return $validator;
    }
}
?>
