# n8n Workflow Error Handling Guide

## Overview

This document describes the comprehensive error handling implementation in the Ad Script Refactor Workflow. The enhanced workflow now includes multiple layers of error detection, handling, and callback mechanisms to ensure robust operation and proper communication with the Laravel application.

## Error Handling Architecture

### 1. Input Validation Layer
- **Node**: `Validate Input`
- **Purpose**: Validates that required fields (`task_id` and `reference_script`) are present
- **Error Handler**: `Process Validation Error`
- **Callback**: Sends error to Laravel via `Error Callback`

### 2. AI Processing Layer
- **Node**: `AI Agent (GPT-4o)`
- **Purpose**: Processes the script using OpenAI's GPT-4o model
- **Timeout**: 60 seconds to prevent hanging
- **Error Scenarios**:
  - API timeout
  - Invalid API credentials
  - Rate limiting
  - Model unavailability
- **Error Handler**: `Process AI Error`
- **Callback**: Sends error to Laravel via `Error Callback`

### 3. Response Validation Layer
- **Node**: `Check AI Response`
- **Purpose**: Validates that the AI response contains expected data
- **Error Handler**: `Process AI Error`
- **Callback**: Sends error to Laravel via `Error Callback`

### 4. Global Error Handling Layer
- **Nodes**: Multiple `Error Trigger` nodes
- **Purpose**: Catches any unexpected errors from any node
- **Error Handler**: `Global Error Handler`
- **Callback**: Sends error to Laravel via `Global Error Callback`

## Error Trigger Nodes

The workflow includes four dedicated error trigger nodes that catch errors from specific workflow sections:

1. **Error Trigger - Webhook**: Catches errors from the webhook trigger
2. **Error Trigger - Variables**: Catches errors from variable setting
3. **Error Trigger - AI**: Catches errors from AI processing
4. **Error Trigger - Callback**: Catches errors from callback operations

All error triggers route to the `Global Error Handler` for consistent error processing.

## Error Processing Logic

### Input Validation Error
```javascript
// Handle input validation error
const taskId = $('Set Variables').first().json.task_id || 'unknown';

return {
  task_id: taskId,
  error: 'Invalid input: missing required fields (task_id or reference_script)',
  status: 'error'
};
```

### AI Processing Error
```javascript
// Handle AI processing error
const taskId = $('Set Variables').first().json.task_id;
const errorMessage = $input.first().json.error || 'AI processing failed';

return {
  task_id: taskId,
  error: `AI processing error: ${errorMessage}`,
  status: 'error'
};
```

### Global Error Handler
```javascript
// Global error handler for unexpected failures
let taskId = 'unknown';
let errorMessage = 'Unknown error occurred';

try {
  // Try to get task_id from various possible sources
  if ($('Set Variables').first() && $('Set Variables').first().json.task_id) {
    taskId = $('Set Variables').first().json.task_id;
  } else if ($input.first() && $input.first().json && $input.first().json.task_id) {
    taskId = $input.first().json.task_id;
  }
  
  // Get error details
  if ($input.first() && $input.first().json && $input.first().json.error) {
    errorMessage = $input.first().json.error.message || $input.first().json.error;
  } else if ($input.first() && $input.first().error) {
    errorMessage = $input.first().error.message || $input.first().error;
  }
} catch (e) {
  errorMessage = 'Critical workflow error: ' + e.message;
}

return {
  task_id: taskId,
  error: `Workflow error: ${errorMessage}`,
  status: 'error'
};
```

## Callback Mechanisms

### Success Callback
- **Endpoint**: `POST /api/ad-scripts/{task_id}/result`
- **Headers**: 
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `X-Source: n8n-workflow`
- **Body**: 
  ```json
  {
    "new_script": "...",
    "analysis": {...}
  }
  ```

### Error Callback
- **Endpoint**: `POST /api/ad-scripts/{task_id}/result`
- **Headers**: Same as success callback
- **Body**: 
  ```json
  {
    "error": "Error description"
  }
  ```

### Global Error Callback
- **Endpoint**: Same as error callback
- **Additional Options**: 
  - `ignoreHttpStatusErrors: true` - Prevents callback failures from causing additional errors
- **Body**: Same as error callback

## Retry Configuration

All callback nodes include retry configuration:
- **Max Retries**: 3
- **Retry Interval**: 1000ms (1 second)
- **Timeout**: 30 seconds

## Webhook Responses

The workflow provides different webhook responses based on the outcome:

### Success Response
```json
{
  "status": "success",
  "message": "Callback sent successfully",
  "task_id": "..."
}
```

### Error Response
```json
{
  "status": "error",
  "message": "Error callback sent",
  "task_id": "..."
}
```

### Critical Error Response
```json
{
  "status": "error",
  "message": "Critical error handled",
  "task_id": "..."
}
```

## Error Scenarios and Handling

### 1. Missing Required Fields
- **Trigger**: Input validation fails
- **Handler**: `Process Validation Error`
- **Response**: Error callback with validation message

### 2. AI API Failures
- **Triggers**: 
  - OpenAI API timeout
  - Invalid credentials
  - Rate limiting
  - Service unavailability
- **Handler**: `Process AI Error`
- **Response**: Error callback with AI error details

### 3. Invalid AI Response
- **Trigger**: AI returns malformed or incomplete response
- **Handler**: `Process AI Error`
- **Response**: Error callback with parsing error

### 4. Callback Failures
- **Trigger**: Laravel endpoint unavailable or returns error
- **Handler**: Retry mechanism (3 attempts)
- **Fallback**: Global error handler if all retries fail

### 5. Unexpected Errors
- **Triggers**: Any unhandled exception in the workflow
- **Handler**: `Global Error Handler`
- **Response**: Critical error callback

## Testing Error Scenarios

To test the error handling implementation:

### 1. Test Input Validation
```bash
curl -X POST http://n8n:5678/webhook/ad-script-processing \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-webhook-token" \
  -d '{
    "task_id": "",
    "reference_script": "console.log('test');"
  }'
```

### 2. Test AI API Error
- Temporarily use invalid OpenAI credentials
- Send a valid request and verify error handling

### 3. Test Callback Error
- Temporarily stop the Laravel application
- Send a valid request and verify retry behavior

### 4. Test Large Script Processing
```bash
curl -X POST http://n8n:5678/webhook/ad-script-processing \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-webhook-token" \
  -d '{
    "task_id": "test-large-script",
    "reference_script": "// Very large script content...",
    "outcome_description": "Optimize this large script"
  }'
```

## Monitoring and Logging

### Workflow Settings
- **Error Workflow**: Enabled for additional error tracking
- **Save Manual Executions**: Enabled for debugging
- **Timezone**: UTC for consistent logging

### Error Tracking
- All errors are logged with task IDs for traceability
- Error messages include context about the failure point
- Callback attempts are logged for monitoring

## Best Practices

1. **Always include task_id**: Ensures errors can be traced back to specific tasks
2. **Provide descriptive error messages**: Helps with debugging and user feedback
3. **Use retry mechanisms**: Handles temporary network or service issues
4. **Implement timeouts**: Prevents workflows from hanging indefinitely
5. **Test error scenarios**: Regularly verify error handling works as expected

## Maintenance

### Regular Checks
- Monitor error rates in n8n execution logs
- Verify callback success rates
- Check for new error patterns

### Updates
- Update error messages for clarity
- Adjust timeout values based on performance
- Add new error scenarios as they are discovered

This comprehensive error handling ensures that the workflow can gracefully handle failures and provide meaningful feedback to the Laravel application, maintaining system reliability and user experience. 