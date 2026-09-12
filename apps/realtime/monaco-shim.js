export class Range {
    constructor(...args) {
        return new window.monaco.Range(...args);
    }
}

export class Selection {
    constructor(...args) {
        return new window.monaco.Selection(...args);
    }

    static createWithDirection(...args) {
        return window.monaco.Selection.createWithDirection(...args);
    }
}

export const editor = {};

export const SelectionDirection = {
    get LTR() {
        return window.monaco.SelectionDirection.LTR;
    },
    get RTL() {
        return window.monaco.SelectionDirection.RTL;
    }
};
