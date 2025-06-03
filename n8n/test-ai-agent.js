/**
 * Test script for validating AI Agent configuration in n8n workflow
 * 
 * This script tests the AI agent's ability to:
 * 1. Process ad script refactoring requests
 * 2. Return properly formatted JSON responses
 * 3. Handle various input scenarios
 */

const testCases = [
  {
    name: "Basic JavaScript Modernization",
    payload: {
      task_id: "test-001",
      reference_script: `
function oldFunction() {
  var x = 1;
  var y = 2;
  return x + y;
}

var result = oldFunction();
console.log(result);
      `.trim(),
      outcome_description: "Modernize to use const/let and arrow functions"
    },
    expectedFields: ["new_script", "analysis"]
  },
  {
    name: "Performance Optimization",
    payload: {
      task_id: "test-002", 
      reference_script: `
function processArray(arr) {
  var result = [];
  for (var i = 0; i < arr.length; i++) {
    for (var j = 0; j < arr.length; j++) {
      if (i !== j) {
        result.push(arr[i] + arr[j]);
      }
    }
  }
  return result;
}
      `.trim(),
      outcome_description: "Optimize for better performance and reduce time complexity"
    },
    expectedFields: ["new_script", "analysis"]
  },
  {
    name: "Error Handling Enhancement",
    payload: {
      task_id: "test-003",
      reference_script: `
function fetchData(url) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, false);
  xhr.send();
  return JSON.parse(xhr.responseText);
}
      `.trim(),
      outcome_description: "Add proper error handling and make it asynchronous"
    },
    expectedFields: ["new_script", "analysis"]
  }
];

/**
 * Validate AI response format
 */
function validateResponse(response, testCase) {
  const errors = [];
  
  try {
    const parsed = JSON.parse(response);
    
    // Check required fields
    testCase.expectedFields.forEach(field => {
      if (!parsed.hasOwnProperty(field)) {
        errors.push(`Missing required field: ${field}`);
      }
    });
    
    // Validate new_script
    if (parsed.new_script && typeof parsed.new_script !== 'string') {
      errors.push('new_script must be a string');
    }
    
    // Validate analysis structure
    if (parsed.analysis) {
      const requiredAnalysisFields = [
        'improvements',
        'performance_impact', 
        'maintainability',
        'potential_issues',
        'recommendations'
      ];
      
      requiredAnalysisFields.forEach(field => {
        if (!parsed.analysis.hasOwnProperty(field)) {
          errors.push(`Missing analysis field: ${field}`);
        }
      });
      
      // Check array fields
      if (parsed.analysis.improvements && !Array.isArray(parsed.analysis.improvements)) {
        errors.push('analysis.improvements must be an array');
      }
      
      if (parsed.analysis.potential_issues && !Array.isArray(parsed.analysis.potential_issues)) {
        errors.push('analysis.potential_issues must be an array');
      }
      
      if (parsed.analysis.recommendations && !Array.isArray(parsed.analysis.recommendations)) {
        errors.push('analysis.recommendations must be an array');
      }
    }
    
    return {
      valid: errors.length === 0,
      errors,
      parsed
    };
    
  } catch (e) {
    return {
      valid: false,
      errors: [`Invalid JSON: ${e.message}`],
      parsed: null
    };
  }
}

/**
 * Test the n8n webhook endpoint
 */
async function testWebhook(testCase, webhookUrl, authSecret) {
  try {
    const response = await fetch(webhookUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Secret': authSecret
      },
      body: JSON.stringify(testCase.payload)
    });
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const result = await response.json();
    return {
      success: true,
      data: result
    };
    
  } catch (error) {
    return {
      success: false,
      error: error.message
    };
  }
}

/**
 * Run all test cases
 */
async function runTests() {
  const webhookUrl = process.env.N8N_WEBHOOK_URL || 'http://localhost:5678/webhook-test/ad-script-refactor-openrouter';
  const authSecret = process.env.N8N_WEBHOOK_SECRET || 'your-webhook-secret';
  
  console.log('🧪 Testing AI Agent Configuration in n8n Workflow');
  console.log('=' .repeat(60));
  console.log(`Webhook URL: ${webhookUrl}`);
  console.log(`Auth Secret: ${authSecret.substring(0, 8)}...`);
  console.log('');
  
  for (const testCase of testCases) {
    console.log(`📋 Test: ${testCase.name}`);
    console.log('-'.repeat(40));
    
    const result = await testWebhook(testCase, webhookUrl, authSecret);
    
    if (result.success) {
      console.log('✅ Webhook request successful');
      console.log(`📤 Response: ${JSON.stringify(result.data, null, 2)}`);
    } else {
      console.log('❌ Webhook request failed');
      console.log(`📤 Error: ${result.error}`);
    }
    
    console.log('');
  }
}

/**
 * Manual response validation (for testing AI output format)
 */
function testResponseFormat() {
  console.log('🔍 Testing Response Format Validation');
  console.log('=' .repeat(60));
  
  const sampleResponses = [
    {
      name: "Valid Response",
      response: JSON.stringify({
        new_script: "const modernFunction = () => { const x = 1; const y = 2; return x + y; };",
        analysis: {
          improvements: ["Converted to arrow function", "Used const instead of var"],
          performance_impact: "Minimal performance impact, improved readability",
          maintainability: "Better code structure and modern syntax",
          potential_issues: ["None identified"],
          recommendations: ["Consider adding JSDoc comments"]
        }
      })
    },
    {
      name: "Invalid Response - Missing Field",
      response: JSON.stringify({
        new_script: "const modernFunction = () => { return 1 + 2; };"
        // Missing analysis field
      })
    },
    {
      name: "Invalid Response - Wrong Type",
      response: JSON.stringify({
        new_script: 123, // Should be string
        analysis: {
          improvements: "Not an array", // Should be array
          performance_impact: "Good",
          maintainability: "Better",
          potential_issues: [],
          recommendations: []
        }
      })
    }
  ];
  
  sampleResponses.forEach(sample => {
    console.log(`📋 ${sample.name}`);
    console.log('-'.repeat(40));
    
    const validation = validateResponse(sample.response, { expectedFields: ["new_script", "analysis"] });
    
    if (validation.valid) {
      console.log('✅ Response format is valid');
    } else {
      console.log('❌ Response format is invalid');
      validation.errors.forEach(error => {
        console.log(`   • ${error}`);
      });
    }
    console.log('');
  });
}

// Export functions for use in other contexts
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    testCases,
    validateResponse,
    testWebhook,
    runTests,
    testResponseFormat
  };
}

// Run tests automatically when script is executed
console.log('🔍 Testing Response Format Validation');
console.log('='.repeat(60));

const sampleResponses = [
  {
    name: "Valid Response",
    response: JSON.stringify({
      new_script: "const modernFunction = () => { const x = 1; const y = 2; return x + y; };",
      analysis: {
        improvements: ["Converted to arrow function", "Used const instead of var"],
        performance_impact: "Minimal performance impact, improved readability",
        maintainability: "Better code structure and modern syntax",
        potential_issues: ["None identified"],
        recommendations: ["Consider adding JSDoc comments"]
      }
    })
  },
  {
    name: "Invalid Response - Missing Field",
    response: JSON.stringify({
      new_script: "const modernFunction = () => { return 1 + 2; };"
      // Missing analysis field
    })
  },
  {
    name: "Invalid Response - Wrong Type",
    response: JSON.stringify({
      new_script: 123, // Should be string
      analysis: {
        improvements: "Not an array", // Should be array
        performance_impact: "Good",
        maintainability: "Better",
        potential_issues: [],
        recommendations: []
      }
    })
  }
];

sampleResponses.forEach(sample => {
  console.log(`📋 ${sample.name}`);
  console.log('-'.repeat(40));
  
  const validation = validateResponse(sample.response, { expectedFields: ["new_script", "analysis"] });
  
  if (validation.valid) {
    console.log('✅ Response format is valid');
  } else {
    console.log('❌ Response format is invalid');
    validation.errors.forEach(error => {
      console.log(`   • ${error}`);
    });
  }
  console.log('');
});

console.log('💡 To run webhook tests, set RUN_WEBHOOK_TESTS=true environment variable');
console.log('   Example: RUN_WEBHOOK_TESTS=true node n8n/test-ai-agent.js');

// Run webhook tests if environment is configured
if (process.env.RUN_WEBHOOK_TESTS === 'true') {
  runTests().catch(console.error);
} 