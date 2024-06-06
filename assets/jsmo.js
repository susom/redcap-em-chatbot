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

                let parsedRes;
                try {
                    parsedRes = JSON.parse(res);
                } catch (e) {
                    console.error("Failed to parse response:", res);
                    errorCallback(e);
                    return;
                }

                if (parsedRes?.response) {
                    callback(parsedRes);
                } else {
                    console.log("No response field in parsed response:", parsedRes);
                }
            } catch (err) {
                errorCallback(err);
            }
        }
    });
}
