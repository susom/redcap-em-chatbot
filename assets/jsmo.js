// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.REDCapChatBot;

    // Extend the official JSMO with new methods
    Object.assign(module, {
        // Ajax function calling 'TestAction'
        InitFunction: function () {
            console.log("Calling this InitFunction() after load...");
        },

        TestAction : (payload, callback, errorCallback) => {
            console.log("calling TestAction()");
            module.ajax('TestAction', payload)
                .then((res) => {
                    if(res?.result)
                        callback(JSON.parse(res?.result))
                }).catch(err => errorCallback(err))
        },

        callAI : (payload, callback, errorCallback) => {
            console.log("calling callAI()");
            module.ajax('callAI', payload)
                .then((res) => {
                    if(res?.result)
                        callback(JSON.parse(res?.result))
                }).catch(err => errorCallback(err))
        }
    });
}
