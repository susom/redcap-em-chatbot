;{
    const module = ExternalModules.Stanford.REDCapChatBot;

    Object.assign(module, {
        InitFunction: function () {
            console.log("Calling this InitFunction() after load...");
        },

        callAI: async (payload, callback, errorCallback) => {
            try {
                // console.log("calling callAI() with payload:", payload);
                const res = await module.ajax('callAI', payload);
                // console.log("Received raw response from backend:", res);

                let parsedRes;
                try {
                    parsedRes = JSON.parse(res);
                } catch (e) {
                    console.error("Failed to parse response:", res);
                    errorCallback(e);
                    return;
                }

                if (parsedRes?.response) {
                    // console.log("Passing response to callback:", parsedRes.response);
                    callback(parsedRes.response); // Pass the response object directly
                } else {
                    console.log("No response field in parsed response:", parsedRes);
                }
            } catch (err) {
                errorCallback(err);
            }
        }
    });
}
