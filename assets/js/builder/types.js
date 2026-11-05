// @flow
export type Condition = {|
    field: string,
    operator: string,
    value: ?string,
|};

export type ConditionSet = {
    match: 'all' | 'any',
    rules: Array<Condition | ConditionSet>,
};

export type Width = {
    desktop: number,
    mobile: number,
    tablet: number,
};

export type Field = {
    // Stable while the key changes, so the server can tell a rename from a new field. A new
    // field has none until it is saved.
    id?: string,
    help: ?string,
    key: string,
    label: string,
    options: {[string]: mixed},
    placeholder: ?string,
    required: boolean,
    showInList: boolean,
    translations: {[string]: Object},
    type: string,
    requiredIf: ?ConditionSet,
    visibleIf: ?ConditionSet,
    width: Width,
};

export type Step = {
    fields: Array<Field>,
    key: string,
    title: string,
    titles: {[string]: string},
    visibleIf: ?ConditionSet,
};

export type Builder = {
    steps: Array<Step>,
};

export type FieldType = {|
    formKey: string,
    group: string,
    groupTitle: string,
    icon: string,
    input: boolean,
    key: string,
    localizedOptions: Array<string>,
    title: string,
|};
