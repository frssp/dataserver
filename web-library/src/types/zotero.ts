export interface ZoteroItem {
  key: string;
  version: number;
  data: {
    key: string;
    version: number;
    itemType: string;
    title: string;
    creators: Array<{
      creatorType: string;
      firstName?: string;
      lastName?: string;
      name?: string;
    }>;
    date: string;
    dateAdded: string;
    dateModified: string;
    tags: Array<{ tag: string; type?: number }>;
    collections: string[];
    relations: Record<string, string | string[]>;
    [field: string]: any;
  };
  meta: {
    numChildren?: number;
    creatorSummary?: string;
    parsedDate?: string;
  };
}

export interface ZoteroCollection {
  key: string;
  version: number;
  data: {
    key: string;
    version: number;
    name: string;
    parentCollection: string | false;
  };
  meta: {
    numCollections: number;
    numItems: number;
  };
}

export interface ZoteroTag {
  tag: string;
  meta: {
    numItems: number;
    type: number;
  };
}

export interface UserInfo {
  userID: number;
  username: string;
  apiKey: string;
  groups: Array<{
    groupID: number;
    name: string;
    type: string;
    libraryID: number;
  }>;
}

export interface LibraryContext {
  type: 'user' | 'group';
  id: number;
  name: string;
}
