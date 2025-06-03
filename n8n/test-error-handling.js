#!/usr/bin/env node

/**
 * n8n Workflow Error Handling Test Suite
 * 
 * This script tests all error scenarios in the enhanced Ad Script Refactor Workflow
 * to ensure comprehensive error handling and proper callback mechanisms.
 */

const axios = require('axios');
const fs = require('fs');
const path = require('path');

// Configuration
const config = {
  n8nWebhookUrl: process.env.N8N_WEBHOOK_URL || 'http://localhost:5678/webhook-test/ad-script-refactor-openrouter',
  webhookToken: process.env.N8N_WEBHOOK_TOKEN || 'your-webhook-token',
  laravelUrl: process.env.LARAVEL_APP_URL || 'http://localhost:8000',
  timeout: 30000, // 30 seconds
};

// Test results tracking
const testResults = {
  passed: 0,
  failed: 0,
  errors: []
};

/**
 * Utility function to make HTTP requests with error handling
 */
async function makeRequest(url, options = {}) {
  try {
    const response = await axios({
      url,
      timeout: config.timeout,
      ...options
    });
    return { success: true, data: response.data, status: response.status };
  } catch (error) {
    return { 
      success: false, 
      error: error.message, 
      status: error.response?.status,
      data: error.response?.data 
    };
  }
}

/**
 * Test helper function
 */
function logTest(testName, passed, details = '') {
  const status = passed ? '✅ PASS' : '❌ FAIL';
  console.log(`${status}: ${testName}`);
  if (details) {
    console.log(`   Details: ${details}`);
  }
  
  if (passed) {
    testResults.passed++;
  } else {
    testResults.failed++;
    testResults.errors.push({ test: testName, details });
  }
  console.log('');
}

/**
 * Test 1: Input Validation Error - Missing task_id
 */
async function testMissingTaskId() {
  console.log('🧪 Testing: Missing task_id validation...');
  
  const payload = {
    reference_script: 'console.log("test");',
    outcome_description: 'Optimize this script'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: payload
  });
  
  const passed = result.success && 
                 result.data?.status === 'error' && 
                 result.data?.message?.includes('Error callback sent');
  
  logTest(
    'Missing task_id validation', 
    passed, 
    passed ? 'Workflow correctly handled missing task_id' : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 2: Input Validation Error - Missing reference_script
 */
async function testMissingReferenceScript() {
  console.log('🧪 Testing: Missing reference_script validation...');
  
  const payload = {
    task_id: 'test-missing-script',
    outcome_description: 'Optimize this script'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: payload
  });
  
  const passed = result.success && 
                 result.data?.status === 'error' && 
                 result.data?.message?.includes('Error callback sent');
  
  logTest(
    'Missing reference_script validation', 
    passed, 
    passed ? 'Workflow correctly handled missing reference_script' : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 3: Input Validation Error - Empty required fields
 */
async function testEmptyRequiredFields() {
  console.log('🧪 Testing: Empty required fields validation...');
  
  const payload = {
    task_id: '',
    reference_script: '',
    outcome_description: 'Optimize this script'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: payload
  });
  
  const passed = result.success && 
                 result.data?.status === 'error' && 
                 result.data?.message?.includes('Error callback sent');
  
  logTest(
    'Empty required fields validation', 
    passed, 
    passed ? 'Workflow correctly handled empty required fields' : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 4: Valid Request Processing
 */
async function testValidRequest() {
  console.log('🧪 Testing: Valid request processing...');
  
  const payload = {
    task_id: 'test-valid-request',
    reference_script: `
      // Simple test script
      function trackClick(element) {
        var data = {
          element: element.tagName,
          timestamp: new Date().getTime()
        };
        
        // Send tracking data
        fetch('/track', {
          method: 'POST',
          body: JSON.stringify(data)
        });
      }
    `,
    outcome_description: 'Optimize this tracking script for better performance'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: payload
  });
  
  const passed = result.success && 
                 result.data?.status === 'success' && 
                 result.data?.task_id === payload.task_id;
  
  logTest(
    'Valid request processing', 
    passed, 
    passed ? 'Workflow successfully processed valid request' : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 5: Large Script Processing
 */
async function testLargeScript() {
  console.log('🧪 Testing: Large script processing...');
  
  // Generate a large script
  const largeScript = `
    // Large advertising script for testing
    (function() {
      var config = {
        apiEndpoint: 'https://api.example.com/track',
        retryAttempts: 3,
        timeout: 5000,
        batchSize: 100
      };
      
      var eventQueue = [];
      var isProcessing = false;
      
      function trackEvent(eventType, data) {
        eventQueue.push({
          type: eventType,
          data: data,
          timestamp: Date.now(),
          sessionId: getSessionId(),
          userId: getUserId()
        });
        
        if (eventQueue.length >= config.batchSize) {
          processBatch();
        }
      }
      
      function processBatch() {
        if (isProcessing || eventQueue.length === 0) return;
        
        isProcessing = true;
        var batch = eventQueue.splice(0, config.batchSize);
        
        sendBatch(batch, 0);
      }
      
      function sendBatch(batch, attempt) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.apiEndpoint);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = config.timeout;
        
        xhr.onload = function() {
          if (xhr.status === 200) {
            isProcessing = false;
            if (eventQueue.length > 0) {
              setTimeout(processBatch, 100);
            }
          } else {
            handleError(batch, attempt);
          }
        };
        
        xhr.onerror = function() {
          handleError(batch, attempt);
        };
        
        xhr.ontimeout = function() {
          handleError(batch, attempt);
        };
        
        xhr.send(JSON.stringify(batch));
      }
      
      function handleError(batch, attempt) {
        if (attempt < config.retryAttempts) {
          setTimeout(function() {
            sendBatch(batch, attempt + 1);
          }, Math.pow(2, attempt) * 1000);
        } else {
          console.error('Failed to send batch after', config.retryAttempts, 'attempts');
          isProcessing = false;
        }
      }
      
      function getSessionId() {
        return sessionStorage.getItem('sessionId') || generateId();
      }
      
      function getUserId() {
        return localStorage.getItem('userId') || 'anonymous';
      }
      
      function generateId() {
        return Math.random().toString(36).substr(2, 9);
      }
      
      // Auto-track page views
      trackEvent('pageview', {
        url: window.location.href,
        title: document.title,
        referrer: document.referrer
      });
      
      // Track clicks
      document.addEventListener('click', function(e) {
        trackEvent('click', {
          element: e.target.tagName,
          id: e.target.id,
          className: e.target.className,
          text: e.target.textContent.substr(0, 100)
        });
      });
      
      // Track form submissions
      document.addEventListener('submit', function(e) {
        trackEvent('form_submit', {
          formId: e.target.id,
          action: e.target.action,
          method: e.target.method
        });
      });
      
      // Process queue on page unload
      window.addEventListener('beforeunload', function() {
        if (eventQueue.length > 0) {
          processBatch();
        }
      });
      
      // Periodic processing
      setInterval(processBatch, 10000);
      
    })();
  `;
  
  const payload = {
    task_id: 'test-large-script',
    reference_script: largeScript,
    outcome_description: 'Optimize this large advertising tracking script for better performance and maintainability'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: payload
  });
  
  const passed = result.success && 
                 (result.data?.status === 'success' || result.data?.status === 'error');
  
  logTest(
    'Large script processing', 
    passed, 
    passed ? `Workflow handled large script: ${result.data?.status}` : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 6: Invalid JSON in request body
 */
async function testInvalidJson() {
  console.log('🧪 Testing: Invalid JSON handling...');
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${config.webhookToken}`
    },
    data: '{"task_id": "test", "reference_script": "console.log(\'test\');", invalid_json}'
  });
  
  // This should either be handled by n8n or return an error
  const passed = !result.success || 
                 (result.success && result.data?.status === 'error');
  
  logTest(
    'Invalid JSON handling', 
    passed, 
    passed ? 'Workflow correctly handled invalid JSON' : `Unexpected response: ${JSON.stringify(result.data)}`
  );
}

/**
 * Test 7: Webhook Authentication
 */
async function testInvalidAuth() {
  console.log('🧪 Testing: Invalid authentication handling...');
  
  const payload = {
    task_id: 'test-invalid-auth',
    reference_script: 'console.log("test");',
    outcome_description: 'Test script'
  };
  
  const result = await makeRequest(config.n8nWebhookUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer invalid-token'
    },
    data: payload
  });
  
  // Should fail authentication
  const passed = !result.success && (result.status === 401 || result.status === 403);
  
  logTest(
    'Invalid authentication handling', 
    passed, 
    passed ? 'Webhook correctly rejected invalid authentication' : `Unexpected response: ${JSON.stringify(result)}`
  );
}

/**
 * Main test runner
 */
async function runTests() {
  console.log('🚀 Starting n8n Workflow Error Handling Tests\n');
  console.log(`Configuration:`);
  console.log(`- n8n Webhook URL: ${config.n8nWebhookUrl}`);
  console.log(`- Laravel URL: ${config.laravelUrl}`);
  console.log(`- Timeout: ${config.timeout}ms\n`);
  
  // Run all tests
  await testMissingTaskId();
  await testMissingReferenceScript();
  await testEmptyRequiredFields();
  await testValidRequest();
  await testLargeScript();
  await testInvalidJson();
  await testInvalidAuth();
  
  // Print summary
  console.log('📊 Test Summary:');
  console.log(`✅ Passed: ${testResults.passed}`);
  console.log(`❌ Failed: ${testResults.failed}`);
  console.log(`📈 Success Rate: ${((testResults.passed / (testResults.passed + testResults.failed)) * 100).toFixed(1)}%`);
  
  if (testResults.failed > 0) {
    console.log('\n❌ Failed Tests:');
    testResults.errors.forEach(error => {
      console.log(`- ${error.test}: ${error.details}`);
    });
  }
  
  // Save results to file
  const resultsFile = path.join(__dirname, 'test-results.json');
  fs.writeFileSync(resultsFile, JSON.stringify({
    timestamp: new Date().toISOString(),
    config,
    results: testResults
  }, null, 2));
  
  console.log(`\n📄 Detailed results saved to: ${resultsFile}`);
  
  // Exit with appropriate code
  process.exit(testResults.failed > 0 ? 1 : 0);
}

// Handle uncaught errors
process.on('unhandledRejection', (reason, promise) => {
  console.error('Unhandled Rejection at:', promise, 'reason:', reason);
  process.exit(1);
});

process.on('uncaughtException', (error) => {
  console.error('Uncaught Exception:', error);
  process.exit(1);
});

// Run tests if this script is executed directly
if (require.main === module) {
  runTests().catch(error => {
    console.error('Test runner failed:', error);
    process.exit(1);
  });
}

module.exports = {
  runTests,
  testResults,
  config
}; 