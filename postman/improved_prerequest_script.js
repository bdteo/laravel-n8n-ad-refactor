// This script should be in the "Pre-request Script" tab for:
// - n8n Callback - Success
// - n8n Callback - Failure
// - n8n Callback - Validation Error (...)

const requestBody = pm.request.body.raw;
const secret = pm.environment.get("n8n_callback_hmac_secret");
const requestName = pm.request.name; // For better logging

if (!requestBody) {
    console.warn(`[${requestName}] Request body is empty. Cannot generate HMAC signature.`);
    // Consider throwing an error if an empty body is truly invalid for HMAC for your use case
    // throw new Error(`[${requestName}] Request body empty, cannot generate HMAC.`);
    pm.environment.set("hmac_signature", "invalid_due_to_empty_body"); // Set a clearly invalid signature
    return;
}

// Check if secret is missing or still a placeholder
const placeholderSecrets = [
    "{{N8N_CALLBACK_HMAC_SECRET_CI}}",
    "{{N8N_CALLBACK_HMAC_SECRET}}",
    "your-local-hmac-secret", // Default from your example local env
    "your-n8n-callback-hmac-secret-here" // Default from your collection variables
];

if (!secret || placeholderSecrets.includes(secret)) {
    console.error(`[${requestName}] Environment variable 'n8n_callback_hmac_secret' is not set correctly or is a placeholder. Current value: '${secret}'. Cannot generate HMAC signature.`);
    // throw new Error(`[${requestName}] HMAC secret not configured. Please check your Postman Environment.`);
    pm.environment.set("hmac_signature", "invalid_due_to_missing_secret"); // Set a clearly invalid signature
    return;
}

try {
    const signature = CryptoJS.HmacSHA256(requestBody, secret).toString(CryptoJS.enc.Hex);
    const fullSignature = "sha256=" + signature;
    pm.environment.set("hmac_signature", fullSignature);
    console.log(`[${requestName}] Generated HMAC Signature: ${fullSignature.substring(0,17)}...`); // Log truncated signature
} catch (e) {
    console.error(`[${requestName}] Error generating HMAC: ${e.message}`);
    // throw new Error(`[${requestName}] HMAC generation failed.`);
    pm.environment.set("hmac_signature", "invalid_due_to_generation_error"); // Set a clearly invalid signature
}
