import Dexie from 'dexie';

export const db_cached_chats = new Dexie('cached_chats');
db_cached_chats.version(1).stores({
  chats : 'session_id, timestamp, queries'
});

export async function deleteAllData(){
  await db_cached_chats.chats.clear();
  console.log(await db_cached_chats.chats.toArray()); // Output: []
};

export async function saveNewSession(session_id, timestamp, queries) {
  try {
    const id = await db_cached_chats.chats.add({session_id, timestamp, queries });
    console.log("Saved document with id:", id);
  } catch (error) {
    console.error("Error saving document:", error);
  }
}

export async function addSessionQuery(id, newQueries) {
  try {
    const document = await db_cached_chats.chats.get(id);
    const query_set = document.queries;
    query_set.push(newQueries);
      
    await db_cached_chats.chats.update(id, { queries: query_set });
    // console.log("Updated queries for document with id:", id);
  } catch (error) {
    console.error("Error updating queries:", error);
  }
}

export async function updateSession(id, updatedQueries) {
  try {
    await db_cached_chats.chats.update(id, { queries: updatedQueries });
    console.log("Updated queries for document with id:", id);
  } catch (error) {
    console.error("Error updating queries:", error);
  }
}

export async function getAllSessions() {
    const allDocuments = await db_cached_chats.chats.toArray();
    // console.log("Retrieved all documents:", allDocuments);
    return allDocuments;
}

export async function getSession(id) {
  try {
    const document = await db_cached_chats.chats.get(id);
    if (document) {
        // console.log("Retrieved document:", document);
    } else {
        console.log("Document not found");
    }
    return document;
  } catch (error) {
    console.error("Error retrieving document:", error);
  }
}

export async function deleteSession(id) {
  try {
    await db_cached_chats.chats.delete(id);
    console.log("Deleted document with id:", id);
  } catch (error) {
    console.error("Error deleting document:", error);
  }
}
