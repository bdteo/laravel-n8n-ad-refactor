/**
 * Test script for validating the improved AI prompt that includes JSON format instructions
 * 
 * This script helps verify that our prompt properly instructs the AI to:
 * 1. Respond in valid JSON format
 * 2. Include the required new_script and analysis fields
 * 3. Structure the analysis object with the expected properties
 */

const sampleInputs = [
  {
    name: "Social Media Ad Copy",
    outcomeDescription: "Rewrite this for a younger audience (18-25), make it sound trendy and exciting for social media. Emphasize limited-time offers and the 'cool factor'. Keep it short, under 140 characters. Tone: Playful and urgent.",
    referenceScript: "Our current summer sale slogan: 'Big Summer Blowout! Everything Must Go!' - it feels a bit dated."
  },
  {
    name: "Professional Email Marketing",
    outcomeDescription: "Transform this into professional email marketing copy for B2B clients. Focus on ROI, efficiency, and business value. Keep it concise but informative. Tone: Professional and persuasive.",
    referenceScript: "Hey everyone! Check out our awesome new productivity tool - it's really cool and will make your work easier!"
  },
  {
    name: "Product Description",
    outcomeDescription: "Create compelling product description copy that highlights benefits over features. Target busy parents looking for convenience. Include emotional appeal and practical benefits. Tone: Warm and helpful.",
    referenceScript: "This vacuum cleaner has 1200W motor, HEPA filter, and 2L dust capacity."
  }
];

/**
 * Generate the complete prompt as it would appear in n8n
 */
function generatePrompt(outcomeDescription, referenceScript) {
  return `You are an expert advertising copy specialist. Your task is to refactor the provided advertising script according to the given outcome description.

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

Requirements: ${outcomeDescription}

Original Script to Refactor:
\`\`\`
${referenceScript}
\`\`\`

Remember: Respond ONLY with the JSON object, no additional text or markdown formatting.`;
}

/**
 * Validate AI response format
 */
function validateResponse(response) {
  const errors = [];
  
  try {
    const parsed = JSON.parse(response);
    
    // Check required top-level fields
    if (!parsed.hasOwnProperty('new_script')) {
      errors.push('Missing required field: new_script');
    }
    
    if (!parsed.hasOwnProperty('analysis')) {
      errors.push('Missing required field: analysis');
    }
    
    // Validate new_script
    if (parsed.new_script !== undefined && typeof parsed.new_script !== 'string') {
      errors.push('new_script must be a string');
    }
    
    if (parsed.new_script !== undefined && parsed.new_script.length === 0) {
      errors.push('new_script cannot be empty');
    }
    
    // Validate analysis structure
    if (parsed.analysis) {
      if (typeof parsed.analysis !== 'object' || Array.isArray(parsed.analysis)) {
        errors.push('analysis must be an object');
      } else {
        const expectedAnalysisFields = [
          'improvements_made',
          'tone_analysis',
          'target_audience_fit',
          'length_compliance',
          'persuasiveness_enhancements',
          'potential_issues',
          'recommendations'
        ];
        
        expectedAnalysisFields.forEach(field => {
          if (!parsed.analysis.hasOwnProperty(field)) {
            errors.push(`Missing analysis field: ${field}`);
          } else if (typeof parsed.analysis[field] !== 'string') {
            errors.push(`analysis.${field} must be a string`);
          }
        });
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
 * Test the prompt generation for all sample inputs
 */
function testPromptGeneration() {
  console.log('🔍 Testing Improved AI Prompt Generation');
  console.log('=' .repeat(80));
  
  sampleInputs.forEach((sample, index) => {
    console.log(`\n📋 Test Case ${index + 1}: ${sample.name}`);
    console.log('-'.repeat(60));
    
    const prompt = generatePrompt(sample.outcomeDescription, sample.referenceScript);
    
    console.log('Generated Prompt:');
    console.log(prompt);
    console.log('\n✅ Prompt generated successfully');
    console.log(`📏 Prompt length: ${prompt.length} characters`);
    
    // Check if prompt includes key elements
    const hasJSONExample = prompt.includes('"new_script":') && prompt.includes('"analysis":');
    const hasFormatInstructions = prompt.includes('ONLY a valid JSON object');
    const hasRequirements = prompt.includes(sample.outcomeDescription);
    const hasOriginalScript = prompt.includes(sample.referenceScript);
    
    console.log(`🎯 Contains JSON example: ${hasJSONExample ? '✅' : '❌'}`);
    console.log(`📝 Contains format instructions: ${hasFormatInstructions ? '✅' : '❌'}`);
    console.log(`📋 Contains requirements: ${hasRequirements ? '✅' : '❌'}`);
    console.log(`📄 Contains original script: ${hasOriginalScript ? '✅' : '❌'}`);
  });
}

/**
 * Test response validation with sample responses
 */
function testResponseValidation() {
  console.log('\n\n🔍 Testing Response Format Validation');
  console.log('=' .repeat(80));
  
  const sampleResponses = [
    {
      name: "Valid Response",
      response: JSON.stringify({
        new_script: "🌟 Summer Deals Alert! Grab the hottest discounts before they're gone! Limited time only! #SummerSale #DealAlert",
        analysis: {
          improvements_made: "Added emojis, trending hashtags, and urgency language to appeal to younger audience",
          tone_analysis: "Transformed from formal to casual, trendy, and urgent",
          target_audience_fit: "High fit for 18-25 demographic with emoji usage and social media language",
          length_compliance: "Kept under 140 characters as requested",
          persuasiveness_enhancements: "Added FOMO with 'before they're gone' and visual appeal with emojis",
          potential_issues: "Emojis may not display consistently across all platforms",
          recommendations: "A/B test with different emoji combinations and consider platform-specific versions"
        }
      })
    },
    {
      name: "Missing new_script",
      response: JSON.stringify({
        analysis: {
          improvements_made: "Some improvements",
          tone_analysis: "Good tone",
          target_audience_fit: "Fits well",
          length_compliance: "Compliant",
          persuasiveness_enhancements: "Enhanced",
          potential_issues: "None",
          recommendations: "Keep testing"
        }
      })
    },
    {
      name: "Missing analysis fields",
      response: JSON.stringify({
        new_script: "Great new script here",
        analysis: {
          improvements_made: "Some improvements",
          tone_analysis: "Good tone"
          // Missing other required fields
        }
      })
    },
    {
      name: "Invalid JSON",
      response: "This is not JSON at all, just plain text response"
    }
  ];
  
  sampleResponses.forEach(sample => {
    console.log(`\n📋 ${sample.name}`);
    console.log('-'.repeat(40));
    
    const validation = validateResponse(sample.response);
    
    if (validation.valid) {
      console.log('✅ Response format is valid');
      console.log('📊 Analysis fields found:', Object.keys(validation.parsed.analysis || {}));
    } else {
      console.log('❌ Response format is invalid');
      validation.errors.forEach(error => {
        console.log(`   • ${error}`);
      });
    }
  });
}

/**
 * Main execution
 */
function main() {
  console.log('🚀 Testing Improved AI Prompt Configuration');
  console.log('📅 Date:', new Date().toISOString());
  console.log('\nThis script validates that our improved prompt includes:');
  console.log('- Clear JSON format instructions');
  console.log('- Required field structure (new_script, analysis)');
  console.log('- All expected analysis sub-fields');
  console.log('- Emphasis on JSON-only responses');
  
  testPromptGeneration();
  testResponseValidation();
  
  console.log('\n\n🎉 Testing completed!');
  console.log('\nNext steps:');
  console.log('1. Deploy the updated n8n workflow');
  console.log('2. Test with actual AI requests');
  console.log('3. Monitor response quality and format compliance');
}

// Run the tests immediately
main();

export {
  generatePrompt,
  validateResponse,
  sampleInputs
}; 