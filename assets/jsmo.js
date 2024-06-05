;{
    const module = ExternalModules.Stanford.REDCapChatBot;

    Object.assign(module, {
        InitFunction: function () {
            console.log("Calling this InitFunction() after load...");
        },

        callAI: (payload, callback, errorCallback) => {
            console.log("calling callAI() with payload:", payload);
            module.ajax('callAI', payload)
                .then((res) => {
                    console.log("Motherfucker Received raw response from backend:", res);
                    if (res?.response) {
                        callback(res.response); // Pass the response object directly
                    } else {
                        console.log("No response field in response:", res);
                    }
                }).catch(err => errorCallback(err))
        }
    });
}
