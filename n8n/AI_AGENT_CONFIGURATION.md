# AI Agent Configuration Documentation

## Overview

The AI Agent (GPT-4o) is configured within the n8n workflow to process ad script refactoring requests. This document details the configuration, validation, and testing procedures for the AI agent.

## Configuration Details

### 1. OpenAI Node Configuration

The AI agent is implemented using the `@n8n/n8n-nodes-langchain.openAi` node with the following configuration:

```json
{
  "model": "gpt-4o",
  "messages": {
    "messageType": "multipleMessages",
    "values": [
      {
        "role": "system",
        "content": "You are an expert JavaScript developer specializing in advertising script optimization..."
      },
      {
        "role": "user", 
        "content": "Please refactor the following advertising script..."
      }
    ]
  },
  "options": {
    "temperature": 0.3,
    "maxTokens": 2000
  }
}
```

### 2. Model Parameters

- **Model**: `gpt-4o` - Latest GPT-4 Optimized model for best performance
- **Temperature**: `0.3` - Low temperature for consistent, focused responses
- **Max Tokens**: `2000` - Sufficient for detailed refactoring and analysis
- **Message Type**: `multipleMessages` - Supports system and user prompts

### 3. AI Prompt Configuration

The AI agent uses a comprehensive prompt that includes both role definition and JSON format requirements:

```
You are an expert advertising copy specialist. Your task is to refactor the provided advertising script according to the given outcome description.

IMPORTANT: You must respond with ONLY a valid JSON object in this exact format:
{
  "new_script": "your refactored advertising script here",
  "analysis": {
    "improvements_made": "description of what you improved",
    "tone_analysis": "analysis of the tone and style",
    "target_audience_fit": "how well it fits the target audience",
    "length_compliance": "whether length requirements were met",
    "persuasiveness_enhancements": "how persuasiveness was improved",
    "potential_issues": "any potential concerns or limitations",
    "recommendations": "additional suggestions for improvement"
  }
}

Requirements: [outcome_description]

Original Script to Refactor:
```
[reference_script]
```

Remember: Respond ONLY with the JSON object, no additional text or markdown formatting.
```

This prompt ensures:
- Clear role definition as an advertising copy specialist
- Explicit JSON format requirements with exact field structure
- Emphasis on responding ONLY with JSON (no markdown or additional text)
- Proper context with requirements and original script
- Structured analysis fields that match our Laravel validation rules

### 4. Input Mapping

The AI agent receives input from the webhook trigger through the "Set Variables" node:

- `{{ $json.reference_script }}` - Original JavaScript code to refactor
- `{{ $json.outcome_description }}` - Desired outcome description

### 5. Response Processing

The AI response is processed through several validation steps:

1. **Response Check**: Validates that AI returned a response
2. **JSON Parsing**: Attempts to parse the AI response as JSON
3. **Field Validation**: Ensures required fields are present
4. **Type Validation**: Verifies field types match expectations

## Response Format Specification

### Success Response

```json
{
  "new_script": "const modernFunction = () => { const x = 1; const y = 2; return x + y; };",
  "analysis": {
    "improvements": [
      "Converted to arrow function",
      "Used const instead of var"
    ],
    "performance_impact": "Minimal performance impact, improved readability",
    "maintainability": "Better code structure and modern syntax",
    "potential_issues": ["None identified"],
    "recommendations": ["Consider adding JSDoc comments"]
  }
}
```

### Error Response

```json
{
  "error": "Failed to parse AI response: Invalid JSON format"
}
```

## Validation Rules

### Required Fields

1. **new_script** (string): The refactored JavaScript code
2. **analysis** (object): Detailed analysis object

### Analysis Object Structure

1. **improvements** (array): List of improvements made
2. **performance_impact** (string): Performance impact description
3. **maintainability** (string): Maintainability improvements
4. **potential_issues** (array): Potential issues or considerations
5. **recommendations** (array): Additional recommendations

## Testing

### Automated Testing

Run the validation test script:

```bash
node n8n/test-ai-agent.js
```

This script validates:
- Response format compliance
- Required field presence
- Data type validation
- Error handling scenarios

### Manual Testing

Test the complete workflow with sample data:

```bash
# Set environment variables
export N8N_WEBHOOK_URL="http://localhost:5678/webhook-test/ad-script-refactor-openrouter"
export N8N_WEBHOOK_SECRET="your-webhook-secret"
export RUN_WEBHOOK_TESTS="true"

# Run full integration tests
node n8n/test-ai-agent.js
```

### Test Cases

The test suite includes three comprehensive test cases:

1. **Basic JavaScript Modernization**
   - Input: Legacy function with var declarations
   - Expected: Modern const/let and arrow functions

2. **Performance Optimization**
   - Input: Inefficient nested loops
   - Expected: Optimized algorithm with better time complexity

3. **Error Handling Enhancement**
   - Input: Synchronous XMLHttpRequest without error handling
   - Expected: Asynchronous fetch with proper error handling

## Integration with Laravel

### Data Flow

1. Laravel job triggers n8n webhook with payload:
   ```json
   {
     "task_id": "uuid-string",
     "reference_script": "original JavaScript code",
     "outcome_description": "description of desired changes"
   }
   ```

2. n8n processes through AI agent and returns:
   ```json
   {
     "task_id": "uuid-string",
     "new_script": "refactored code",
     "analysis": { ... },
     "status": "success"
   }
   ```

3. Laravel receives callback and updates task status

### DTO Compliance

The AI response format is designed to match the `N8nResultPayload` DTO:

```php
readonly class N8nResultPayload
{
    public function __construct(
        public ?string $newScript = null,
        public ?array $analysis = null,
        public ?string $error = null,
    ) {}
}
```

## Error Handling

### AI Processing Errors

1. **Invalid JSON Response**: Caught and converted to error callback
2. **Missing Required Fields**: Validated and reported
3. **API Failures**: Network/API errors handled gracefully
4. **Timeout Handling**: 30-second timeout with 3 retries

### Fallback Mechanisms

1. **Response Validation**: Multiple validation layers
2. **Error Callbacks**: Structured error reporting to Laravel
3. **Retry Logic**: Automatic retries for transient failures
4. **Logging**: Comprehensive execution logging in n8n

## Monitoring and Maintenance

### Key Metrics

1. **Success Rate**: Percentage of successful AI processing
2. **Response Time**: Average processing time per request
3. **Token Usage**: OpenAI API token consumption
4. **Error Patterns**: Common failure modes

### Maintenance Tasks

1. **Prompt Optimization**: Regular review and improvement of system prompt
2. **Model Updates**: Evaluate newer OpenAI models as they become available
3. **Performance Tuning**: Adjust temperature and token limits based on results
4. **Cost Monitoring**: Track OpenAI API usage and costs

## Security Considerations

1. **API Key Management**: Secure storage in n8n credentials
2. **Input Validation**: Sanitize input scripts before processing
3. **Output Validation**: Validate AI responses before forwarding
4. **Rate Limiting**: Implement appropriate rate limits for API calls

## Troubleshooting

### Common Issues

1. **AI Returns Invalid JSON**
   - Check system prompt clarity
   - Verify input script format
   - Review temperature settings

2. **Missing Analysis Fields**
   - Validate system prompt requirements
   - Check AI response parsing logic
   - Verify field name consistency

3. **Performance Issues**
   - Monitor token usage
   - Adjust max tokens if needed
   - Consider prompt optimization

### Debug Steps

1. Check n8n execution logs
2. Validate input data format
3. Test with simplified prompts
4. Monitor OpenAI API status
5. Verify credential configuration

## Configuration Checklist

- [ ] OpenAI API credentials configured
- [ ] Model set to `gpt-4o`
- [ ] Temperature set to `0.3`
- [ ] Max tokens set to `2000`
- [ ] System prompt includes JSON format requirements
- [ ] Input mapping configured correctly
- [ ] Response validation implemented
- [ ] Error handling configured
- [ ] Callback endpoints configured
- [ ] Test script validates response format
- [ ] Integration tests pass
- [ ] Monitoring configured

## Conclusion

The AI Agent configuration is comprehensive and production-ready, with proper validation, error handling, and testing mechanisms in place. The configuration ensures reliable processing of ad script refactoring requests while maintaining compatibility with the Laravel application's DTO structure. 